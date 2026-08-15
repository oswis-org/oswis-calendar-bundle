<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Accommodation;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\RoommatePreference;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\RoommatePreferenceRepository;

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
        $sameEvent = $this->findCandidates($text, $this->ownEventScope($participant), $participant);
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
     * Hledá se DOTAZEM DO DB, ne procházením hydratovaných entit. Důvod je provozní: rozsah je
     * celý turnus (stovky lidí) a porovnání v PHP by muselo volat `Participant::getName()`, který
     * NENÍ čistý getter — mutuje entitu, a nad L2-cachovaným grafem to už jednou skončilo
     * OutOfMemory ({@see feedback_getname_mutates_l2cache_oom}). Tady navíc jde o ZÁPISOVOU cestu,
     * takže by se to platilo při každém uložení požadavku.
     *
     * Bezdiakritičnost („reznicek" najde „Řezníček") zajišťuje kolace sloupce
     * `utf8mb4_unicode_ci` — ověřeno dotazem lokálně i na produkci, ne odhadem.
     *
     * Hydratují se až nalezení kandidáti, kterých je typicky nula nebo jeden.
     *
     * @param list<int> $eventIds rozsah akcí, ve kterých se hledá
     *
     * @return list<Participant>
     */
    private function findCandidates(string $text, array $eventIds, Participant $exclude): array
    {
        $needle = trim($text);
        if ([] === $eventIds || '' === $needle) {
            return [];
        }
        // `%` a `_` mají v LIKE zvláštní význam — bez escapování by požadavek „100%" našel kohokoli.
        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle);

        /** @var list<int|string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT p.id FROM calendar_participant p'
            .' JOIN address_book_abstract_contact c ON c.id = p.contact_id'
            .' WHERE p.event_id IN (:events) AND p.deleted_at IS NULL AND p.id <> :exclude'
            // Hledat v OBOU podobách jména: `sortable_name` je „Eliášová Alena", kdežto tým
            // požadavek zapíše v běžném pořadí („Alena Eliášová"). Jen jeden sloupec = polovina
            // zadání se nespáruje (odhaleno testem).
            ." AND (c.sortable_name LIKE :needle ESCAPE '\\\\' OR c.name LIKE :needle ESCAPE '\\\\')"
            .' LIMIT 5', // stačí rozlišit „nikdo / právě jeden / víc" — víc se stejně nepáruje
            [
                'events'  => $eventIds,
                'exclude' => $exclude->getId() ?? 0,
                'needle'  => '%'.$needle.'%',
            ],
            ['events' => ArrayParameterType::INTEGER],
        );

        $candidates = [];
        foreach ($ids as $id) {
            $candidate = $this->em->find(Participant::class, (int) $id);
            if ($candidate instanceof Participant) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Rozsah „vlastní turnus" — jen akce, na kterou je člověk přihlášený.
     *
     * @return list<int>
     */
    private function ownEventScope(Participant $participant): array
    {
        $id = $participant->getEvent()?->getId();

        return null === $id ? [] : [$id];
    }

    /**
     * Rozsah „sourozenecké turnusy" — všechny akce pod touž nadřazenou akcí. Prázdný, když
     * účastník nadřazenou akci nemá (jednorázová akce → konflikt „jiný turnus" nemůže nastat).
     *
     * @return list<int>
     */
    private function siblingScope(Participant $participant): array
    {
        $parentId = $participant->getEvent()?->getSuperEvent()?->getId();
        if (null === $parentId) {
            return [];
        }

        $ids = [];
        foreach ($this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM calendar_event WHERE super_event_id = :parent AND deleted_at IS NULL',
            ['parent' => $parentId],
        ) as $id) {
            if (is_int($id) || is_string($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private function sameEvent(Participant $one, Participant $other): bool
    {
        $oneEvent = $one->getEvent()?->getId();

        return null !== $oneEvent && $oneEvent === $other->getEvent()?->getId();
    }
}
