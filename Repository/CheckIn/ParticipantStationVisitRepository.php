<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\CheckIn;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\CheckInStation;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\ParticipantStationVisit;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;

/**
 * @extends ServiceEntityRepository<ParticipantStationVisit>
 */
class ParticipantStationVisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantStationVisit::class);
    }

    /** Existující visit pro (participant, station) — pro idempotentní upsert. */
    public function findOneByParticipantAndStation(
        Participant $participant,
        CheckInStation $station,
    ): ?ParticipantStationVisit {
        $result = $this->findOneBy(['participant' => $participant, 'station' => $station]);

        return $result instanceof ParticipantStationVisit ? $result : null;
    }

    /**
     * Počet SPLNĚNÝCH visitů per stanice pro daný turnus — reálný SQL COUNT group-by (žádné load-all).
     * Klíč = station id, hodnota = počet účastníků, co stanicí prošli. Pozor na správnost počtů
     * ({@see feedback_getname_mutates_l2cache_oom} — jen COUNT, žádná hydratace).
     *
     * @return array<int, int>
     */
    public function countCompletedGroupedByStation(Event $event): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.station) AS stationId, COUNT(v.id) AS cnt')
            ->innerJoin('v.station', 's')
            ->innerJoin('v.participant', 'p')
            ->where('s.event = :event')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('event', $event)
            ->groupBy('v.station')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['stationId'], $row['cnt'])
                && is_numeric($row['stationId']) && is_numeric($row['cnt'])) {
                $out[(int) $row['stationId']] = (int) $row['cnt'];
            }
        }

        return $out;
    }
}
