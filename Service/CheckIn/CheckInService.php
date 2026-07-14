<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\CheckIn;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\CheckInStation;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\ParticipantStationVisit;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\CheckIn\CheckInStationRepository;
use OswisOrg\OswisCalendarBundle\Repository\CheckIn\ParticipantStationVisitRepository;

/**
 * Doménová logika check-in pipeline — příjezd + splnění stanic + progress.
 *
 * Klíčové vlastnosti (spec §8/§9): zápisy jsou IDEMPOTENTNÍ a razítkované, aby fungovala offline
 * fronta (poslední-zápis-vyhrává). `recordStationVisit` = upsert per (participant, station).
 * Počty přes reálný SQL COUNT (žádné load-all) — {@see feedback_getname_mutates_l2cache_oom}.
 */
class CheckInService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantStationVisitRepository $visitRepository,
        private readonly CheckInStationRepository $stationRepository,
    ) {
    }

    /** Nastaví/zruší příjezd účastníka (arrivedAt). null = zrušit (oprava omylu / no-show). */
    public function setArrival(Participant $participant, ?DateTime $arrivedAt): void
    {
        $participant->setArrivedAt($arrivedAt);
        $this->em->flush();
    }

    /** Toggle příjezdu — neoznačený → teď; označený → zrušit. Vrátí nový stav. */
    public function toggleArrival(Participant $participant): bool
    {
        $now = $participant->isArrived() ? null : new DateTime();
        $this->setArrival($participant, $now);

        return $participant->isArrived();
    }

    /**
     * Idempotentní upsert splnění stanice. Existuje-li visit (participant × station), aktualizuje
     * hodnotu/poznámku/čas (poslední-zápis-vyhrává); jinak vytvoří. `completedAt` z klienta (offline).
     */
    public function recordStationVisit(
        Participant $participant,
        CheckInStation $station,
        ?string $value = null,
        ?string $note = null,
        ?DateTime $completedAt = null,
    ): ParticipantStationVisit {
        $visit = $this->visitRepository->findOneByParticipantAndStation($participant, $station);
        if (!$visit instanceof ParticipantStationVisit) {
            $visit = new ParticipantStationVisit($completedAt);
            $visit->setParticipant($participant);
            $visit->setStation($station);
            $this->em->persist($visit);
        } elseif (null !== $completedAt) {
            $visit->setCompletedAt($completedAt);
        }
        $visit->setValue($value);
        $visit->setNote($note);
        // Evidence stanice = příjezd: splnění evidence zároveň označí arrivedAt (jedna mechanika,
        // drží present-count/no-show). Ostatní stanice vidí jen ty, co prošli evidencí = mají arrivedAt.
        if (CheckInStation::KIND_EVIDENCE === $station->getStationKind() && !$participant->isArrived()) {
            $participant->setArrivedAt($visit->getCompletedAt() ?? new DateTime());
        }
        $this->em->flush();

        return $visit;
    }

    /** Zruší splnění stanice (překlik omylem). No-op, pokud visit neexistuje. */
    public function removeStationVisit(Participant $participant, CheckInStation $station): void
    {
        $visit = $this->visitRepository->findOneByParticipantAndStation($participant, $station);
        if ($visit instanceof ParticipantStationVisit) {
            $this->em->remove($visit);
            $this->em->flush();
        }
    }

    /**
     * Pipeline turnusu = stanice v pořadí + počet splněných na každé (reálný SQL COUNT).
     * Pro progress dashboard: každá položka = ['station' => CheckInStation, 'completed' => int].
     *
     * @return list<array{station: CheckInStation, completed: int}>
     */
    public function getPipeline(Event $event): array
    {
        $stations = $this->stationRepository->getByEvent($event);
        $counts = $this->visitRepository->countCompletedGroupedByStation($event);
        $pipeline = [];
        foreach ($stations as $station) {
            $id = $station->getId();
            $pipeline[] = [
                'station' => $station,
                'completed' => null !== $id ? ($counts[$id] ?? 0) : 0,
            ];
        }

        return $pipeline;
    }
}
