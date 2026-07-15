<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Document;

use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractPerson;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCoreBundle\Service\ExportService;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use Twig\Environment;

/**
 * Generátor provozních dokumentů vázaných na účastníky/turnus (#212 / STOPA 2.5) — server-side PDF
 * přes core {@see ExportService} (mPDF, brandovaná hlavička/patička). Vzor = {@see \OswisOrg\OswisCalendarBundle\Service\Program\ProgramOutputService}.
 *
 * v1 = **bezpečnostní list k podpisu** (à la reálný `Bezpečnostní list 2025.docx`): per-osoba předvyplněné
 * PDF (jméno + datum narození z přihlášky) → tisk → podpis → archiv. Sjednocuje check-in spec §7
 * (SafetyListDocument seam) i #212 Fáze 3. Text prohlášení je legislativně fixní (v šabloně).
 * Papír je plnohodnotný fallback (účastníci bez mobilu).
 *
 * Každá metoda má `…Html` variantu (test může asertovat HTML bez renderu PDF).
 */
final class OperationalDocumentService
{
    private const string TPL = '@OswisOrgOswisCalendar/web_admin/documents/';

    public function __construct(
        private readonly Environment $twig,
        private readonly ExportService $exportService,
        private readonly ParticipantRepository $participantRepository,
    ) {
    }

    /**
     * HTML bezpečnostního listu — 1 prohlášení na stranu. `$only` = jen jeden účastník (stanice bezpečnost),
     * jinak celý turnus (hromadný tisk).
     */
    public function safetyListHtml(Event $event, ?Participant $only = null): string
    {
        $participants = null !== $only ? [$only] : $this->loadAttendees($event);
        $people = array_map(fn(Participant $p): array => $this->toSigningRow($p), $participants);

        return $this->twig->render(self::TPL.'safety-list.pdf.html.twig', [
            'eventName' => $event->getName(),
            'people'    => $people,
        ]);
    }

    public function safetyListPdf(Event $event, ?Participant $only = null): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->safetyListHtml($event, $only),
            false, // portrét (A4 prohlášení)
            'Bezpečnostní list — '.($event->getName() ?? ''),
        );
    }

    /**
     * HTML seznamu účastníků dle skupiny/pásku (#212 F4) — sekce per skupina v pořadí výdeje jídla
     * (`mealOrder`; dietáři = 1. skupina), řádek = jméno · telefon · dietní omezení. Papírový fallback
     * pro stanici pásky + výdej stravy (kuchyň chce jména dietářů). Bez skupiny = zvlášť na konci.
     */
    public function bandListHtml(Event $event): string
    {
        $attendees = $this->loadAttendees($event);
        $ids = array_values(array_filter(array_map(static fn(Participant $p): ?int => $p->getId(), $attendees)));
        if ([] !== $ids) {
            // Anti-N+1: napřed nahřej flagy + kontaktní detaily (telefon) hromadně.
            $this->participantRepository->primeAggregationCollections($ids, true);
        }

        /** @var array<string, array{name: string, color: ?string, mealOrder: int, rows: list<array{name: string, phone: string, diet: string}>}> $groups */
        $groups = [];
        foreach ($attendees as $p) {
            $group = $p->getGroup();
            $key = null !== $group?->getId() ? 'g'.$group->getId() : 'none';
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'name'      => $group?->getName() ?? 'Bez skupiny',
                    'color'     => $group?->getColor(),
                    'mealOrder' => $group?->getMealOrder() ?? PHP_INT_MAX,
                    'rows'      => [],
                ];
            }
            $contact = $p->getContactForRead();
            $groups[$key]['rows'][] = [
                'name'  => $contact?->getName() ?? ('#'.$p->getId()),
                'phone' => (string) $contact?->getPhone(),
                'diet'  => $this->dietNames($p),
            ];
        }

        $ordered = array_values($groups);
        usort($ordered, static fn(array $a, array $b): int => $a['mealOrder'] <=> $b['mealOrder']);

        return $this->twig->render(self::TPL.'band-list.pdf.html.twig', [
            'eventName' => $event->getName(),
            'groups'    => $ordered,
        ]);
    }

    public function bandListPdf(Event $event): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->bandListHtml($event),
            false,
            'Seznam dle pásku — '.($event->getName() ?? ''),
        );
    }

    /** Názvy dietních omezení účastníka (kategorie food) čárkou — pro kuchyň. */
    private function dietNames(Participant $participant): string
    {
        $names = [];
        foreach ($participant->getFlags(null, RegistrationFlagCategory::TYPE_FOOD) as $flag) {
            if (!$flag instanceof RegistrationFlag) {
                continue;
            }
            $name = $flag->getName();
            if (is_string($name) && '' !== $name) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Účastníci turnusu (TYPE_ATTENDEE — konzistentní s check-inem/dashboardem), seřazení česky dle jména.
     *
     * @return list<Participant>
     */
    private function loadAttendees(Event $event): array
    {
        /** @var list<Participant> $participants */
        $participants = $this->participantRepository->getParticipants([
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 0,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => ParticipantCategory::TYPE_ATTENDEE,
        ], true)->getValues();

        usort(
            $participants,
            static fn(Participant $a, Participant $b): int => StringUtils::compareCzech(
                $a->getContact()?->getName(),
                $b->getContact()?->getName(),
            ),
        );

        return $participants;
    }

    /**
     * Read-only řádek pro podpis (žádná mutace entity — jen jméno + datum narození).
     *
     * @return array{name: string, birthDate: string}
     */
    private function toSigningRow(Participant $p): array
    {
        $contact = $p->getContact();
        $birthDate = '';
        if ($contact instanceof AbstractPerson && null !== $contact->getBirthDate()) {
            $birthDate = $contact->getBirthDate()->format('j. n. Y');
        }

        return [
            'name'      => $contact?->getName() ?? ('#'.$p->getId()),
            'birthDate' => $birthDate,
        ];
    }
}
