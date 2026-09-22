<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;

/**
 * JEDINÁ definice toho, kdo je „tým akce".
 *
 * PROČ vznikl (19. 9. 2026): tatáž definice byla **nakopírovaná na dvou místech** — v rozpisu
 * služeb a v programu, obě jako soukromá metoda `staffPool()` — a **obě byly špatně stejnou
 * vadou**. To je přesně ten důvod, proč se odvozené množiny nesmí psát ad hoc: rozejdou se,
 * nebo se pokazí obě naráz a nikdo si toho nevšimne.
 *
 * ## Co se opravilo
 *
 * Původní definice brala typy `organizer | staff | manager`. Jenže kategorie **„Pořadatel"
 * (`organizer`) je v datech pořádající SPOLEK, ne člověk**: všech 16 přihlášek té kategorie je
 * `STUDENTLIFE z. s.`, jedna za ročník 2012–2026. Pool týmu pro turnus 2026 tedy vracel právě
 * jednu položku — a byl to spolek nabízený k obsazení směny.
 *
 * ## Proč právě `staff ∪ manager`
 *
 * Podle vymezení uživatele (18. 9.) je „Člen týmu" tým akce a „Manažer" člověk z organizačního
 * týmu, který je **zároveň** v týmu akce. Sjednocení tedy neplyne ze zvyku, ale z definice.
 * „Personál" sdílí `type = staff` s „Členem týmu" — dnes to nevadí (kategorie je prázdná,
 * personál kempu neevidujeme), ale **až se plnit začne, musí se filtrovat podle KATEGORIE,
 * ne podle typu**.
 *
 * ## Proč se nešplhá na ročník
 *
 * Rozpis služeb dřív scopoval na `getSuperEvent()` s odůvodněním „tým bývá registrovaný na
 * rodičovské akci". To přestává platit: přihláška je **(člověk, turnus)** — rozhodnutí uživatele
 * ze 14. 6. 2026 — a provozní údaje (pokoj, příjezd, páska) to vynucují, protože unikát
 * `uniq_reservation_active` dovolí jednu aktivní rezervaci na přihlášku. Šplhání na ročník by
 * navíc při per-turnusovém týmu vrátilo **každého člověka dvakrát** (dvě přihlášky, dvě id).
 *
 * @see \OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory
 */
final readonly class EventTeamResolver
{
    /**
     * Kategorie, které tvoří tým akce.
     *
     * ⛔ `ParticipantCategory::TYPE_ORGANIZER` sem NEPATŘÍ — je to právnická osoba pořadatele.
     *
     * @var list<string>
     */
    public const array TEAM_TYPES = [
        ParticipantCategory::TYPE_STAFF,
        ParticipantCategory::TYPE_MANAGER,
    ];

    public function __construct(private ParticipantRepository $participantRepository)
    {
    }

    /**
     * Členové týmu dané akce, bez duplicit, seřazení česky podle jména.
     *
     * @return list<Participant>
     */
    public function members(Event $event): array
    {
        $pool = [];
        // CRITERIA_PARTICIPANT_TYPE umí jen jeden typ, proto se sjednocuje po typech.
        // Klíčování podle id přihlášky zároveň odstraní překryv mezi dotazy.
        foreach (self::TEAM_TYPES as $type) {
            foreach ($this->participantRepository->getParticipants([
                ParticipantRepository::CRITERIA_EVENT                 => $event,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 3,
                ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $type,
            ]) as $participant) {
                if ($participant instanceof Participant && null !== $participant->getId()) {
                    $pool[$participant->getId()] = $participant;
                }
            }
        }
        $members = array_values($pool);
        // České řazení (Collator cs_CZ) — `strcoll` je v C-locale bytová komparace a hází
        // diakritiku za „z" (viz WebAdminCheckInController::compareParticipants).
        usort(
            $members,
            static fn (Participant $a, Participant $b): int => StringUtils::compareCzech(
                $a->getSortableName(),
                $b->getSortableName(),
            ),
        );

        return $members;
    }
}
