<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Accommodation;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\RoommatePreference;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\RoommatePreferenceRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantListFilter;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;

/**
 * Zápisová a párovací logika pro preference spolubydlení.
 *
 * Existuje proto, že entita {@see RoommatePreference} je záměrně READ-ONLY přes API — spec
 * (§5) říká „ZÁPIS přes web admin / službu (řeší se párování + konflikt)". Tahle třída je ta
 * služba: převede volný text („chci s Novákovou") na skutečnou vazbu na účastníka a rovnou
 * rozhodne, jestli je požadavek splnitelný.
 *
 * PROČ SE PÁRUJE, A NE JEN UKLÁDÁ TEXT: samotný text je pro ubytovací stanici k ničemu — u
 * příjezdu potřebuje vědět, kam už ten druhý člověk šel. To jde jen přes ID, ne přes jméno.
 *
 * Stavy podle spec §5 („detekce konfliktu: osoba na obou turnusech, neexistující jméno"):
 *  - `STATUS_OK`        — jméno sedí na právě jednoho účastníka TÉHOŽ turnusu,
 *  - `STATUS_CONFLICT`  — sedí, ale na někoho z JINÉHO turnusu (nemůžou spolu bydlet, nejsou
 *                         tam ve stejnou dobu), nebo je jméno víceznačné,
 *  - `STATUS_UNMATCHED` — nesedí na nikoho (překlep, neexistující jméno, ještě se nepřihlásil).
 *
 * `UNMATCHED` NENÍ konečný stav: lidé se hlásí průběžně, takže {@see resolveUnmatched()} se dá
 * pustit znovu a jméno, které dnes nesedí, se zítra spáruje.
 */
class RoommatePreferenceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoommatePreferenceRepository $repository,
        private readonly ParticipantService $participantService,
        private readonly ParticipantListFilter $listFilter,
    ) {
    }

    /**
     * Zaznamená požadavek na spolubydlení a rovnou ho spáruje.
     *
     * @param string $preferenceText volný text tak, jak dorazil (jméno, „chatka pro 4"…)
     * @param string $source         {@see RoommatePreference::SOURCE_REGISTRATION} apod.
     */
    public function record(
        Participant $participant,
        string $preferenceText,
        string $source = RoommatePreference::SOURCE_EMAIL,
        ?Participant $withParticipant = null,
    ): RoommatePreference {
        $preference = new RoommatePreference(trim($preferenceText), $source);
        $preference->setParticipant($participant);
        $preference->setWithParticipant($withParticipant);
        $this->resolve($preference);

        $this->em->persist($preference);
        $this->em->flush();

        return $preference;
    }

    /**
     * Spáruje preferenci s účastníkem a nastaví stav. Idempotentní — dá se pouštět opakovaně.
     *
     * Když už je spolubydlící vybraný ručně (tým ho klikl ze seznamu), párování se přeskočí a
     * ověří se jen turnus; ruční volba má vždycky přednost před hádáním z textu.
     */
    public function resolve(RoommatePreference $preference): void
    {
        $participant = $preference->getParticipant();
        if (!$participant instanceof Participant) {
            $preference->setStatus(RoommatePreference::STATUS_UNMATCHED);

            return;
        }

        $with = $preference->getWithParticipant();
        if ($with instanceof Participant) {
            $preference->setStatus(
                $this->sameEvent($participant, $with)
                    ? RoommatePreference::STATUS_OK
                    : RoommatePreference::STATUS_CONFLICT,
            );

            return;
        }

        $text = trim((string) $preference->getPreferenceText());
        if ('' === $text) {
            $preference->setStatus(RoommatePreference::STATUS_UNMATCHED);

            return;
        }

        // 1) Nejdřív ve VLASTNÍM turnusu — tam je shoda rovnou použitelná.
        $sameEvent = $this->findCandidates($text, $participant->getEvent(), $participant);
        if (1 === count($sameEvent)) {
            $preference->setWithParticipant($sameEvent[0]);
            $preference->setStatus(RoommatePreference::STATUS_OK);

            return;
        }
        if (count($sameEvent) > 1) {
            // Víceznačné jméno (dva Novákové) — spárovat naslepo by bylo horší než nespárovat.
            $preference->setStatus(RoommatePreference::STATUS_CONFLICT);

            return;
        }

        // 2) Pak v sourozeneckých turnusech — shoda tady je právě ten konflikt ze spec:
        //    člověk existuje, ale je na jiném turnusu, takže spolu bydlet nemůžou.
        if ([] !== $this->findCandidates($text, $this->siblingScope($participant), $participant)) {
            $preference->setStatus(RoommatePreference::STATUS_CONFLICT);

            return;
        }

        $preference->setStatus(RoommatePreference::STATUS_UNMATCHED);
    }

    /**
     * Znovu projde nespárované preference — pro případ, že se hledaný člověk přihlásil až potom.
     *
     * @return array{resolved: int, conflict: int, stillUnmatched: int}
     */
    public function resolveUnmatched(): array
    {
        $stats = ['resolved' => 0, 'conflict' => 0, 'stillUnmatched' => 0];
        foreach ($this->repository->findUnmatched() as $preference) {
            $this->resolve($preference);
            $key = match ($preference->getStatus()) {
                RoommatePreference::STATUS_OK       => 'resolved',
                RoommatePreference::STATUS_CONFLICT => 'conflict',
                default                             => 'stillUnmatched',
            };
            ++$stats[$key];
        }
        $this->em->flush();

        return $stats;
    }

    /**
     * Účastníci, se kterými chce daný člověk bydlet — z OBOU stran vazby a jen ty spárované.
     *
     * Obousměrnost: požadavek zadá tým typicky jen u jednoho z dvojice (přišel e-mail od
     * jednoho z nich). Kdyby se četla jen jedna strana, ubytovací stanice by u toho druhého
     * o dvojici nevěděla.
     *
     * @return list<Participant>
     */
    public function getMatchedRoommates(Participant $participant): array
    {
        $roommates = [];
        foreach ($this->repository->findRelatedToParticipant($participant) as $preference) {
            if (RoommatePreference::STATUS_OK !== $preference->getStatus()) {
                continue;
            }
            $other = $preference->getParticipant()?->getId() === $participant->getId()
                ? $preference->getWithParticipant()
                : $preference->getParticipant();
            $otherId = $other?->getId();
            // Klíčováno podle ID kvůli deduplikaci — táž dvojice může mít požadavek z obou stran.
            if ($other instanceof Participant && null !== $otherId && $otherId !== $participant->getId()) {
                $roommates[$otherId] = $other;
            }
        }

        return array_values($roommates);
    }

    public function find(int $id): ?RoommatePreference
    {
        return $this->repository->find($id);
    }

    /**
     * Všechny požadavky, které se účastníka týkají — z obou stran vazby, bez ohledu na stav
     * (i konfliktní a nespárované; právě ty potřebuje tým vidět a dořešit).
     *
     * @return list<RoommatePreference>
     */
    public function findRelatedTo(Participant $participant): array
    {
        return $this->repository->findRelatedToParticipant($participant);
    }

    /** Smaže preferenci. Natvrdo — je to přepis požadavku, originál (e-mail/poznámka) zůstává. */
    public function remove(RoommatePreference $preference): void
    {
        $this->em->remove($preference);
        $this->em->flush();
    }

    /**
     * Účastníci daného rozsahu, jejichž jméno odpovídá hledanému textu.
     *
     * Shodu dělá {@see ParticipantListFilter::participantMatchesQuery()} — tedy přesně to, co
     * hledání v seznamu účastníků. Záměrně: co tým najde v seznamu, to se spáruje i tady, a
     * diakritika ani formát telefonu do toho nemluví.
     *
     * @return list<Participant>
     */
    private function findCandidates(string $text, ?Event $scope, Participant $exclude): array
    {
        if (!$scope instanceof Event) {
            return [];
        }
        $candidates = [];
        foreach ($this->scopeParticipants($scope) as $candidate) {
            if ($candidate->getId() === $exclude->getId() || $candidate->isDeleted()) {
                continue;
            }
            if ($this->listFilter->participantMatchesQuery($candidate, $text)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Rodičovská akce — rozsah, ve kterém leží sourozenecké turnusy. Null, když účastník žádnou
     * nadřazenou akci nemá (jednorázová akce → konflikt „jiný turnus" nemůže nastat).
     */
    private function siblingScope(Participant $participant): ?Event
    {
        return $participant->getEvent()?->getSuperEvent();
    }

    /**
     * @return iterable<Participant>
     */
    private function scopeParticipants(Event $event): iterable
    {
        return $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_EVENT                  => $event,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH  => 3,
        ]);
    }

    private function sameEvent(Participant $one, Participant $other): bool
    {
        $oneEvent = $one->getEvent()?->getId();

        return null !== $oneEvent && $oneEvent === $other->getEvent()?->getId();
    }
}
