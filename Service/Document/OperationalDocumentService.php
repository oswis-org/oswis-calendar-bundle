<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Document;

use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractPerson;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Meal\Meal;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Repository\Meal\MealRepository;
use OswisOrg\OswisCalendarBundle\Repository\Meal\ParticipantMealChoiceRepository;
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
        private readonly MealRepository $mealRepository,
        private readonly ParticipantMealChoiceRepository $mealChoiceRepository,
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

    /**
     * HTML kuchyňského listu (vize B7) — kolik čeho uvařit.
     *
     * Dvě části, protože kuchyň potřebuje dvě různé věci:
     *
     *  1. **Počty per jídlo × varianta, BEZ jmen.** Kuchař vaří hrnce, ne porce pro konkrétní lidi.
     *     Navíc „kdo si co dal k obědu" je osobní údaj, který v kuchyni nemá co dělat.
     *  2. **Dietáři SE JMÉNY a poznámkou.** Tady je to naopak: omezení znamená zvláštní porci,
     *     kterou někdo konkrétní přebírá u výdeje — bez jména by ji nebylo komu dát.
     *
     * **„Bez volby" je stejně důležité jako počty variant.** Kdo si nevybral, stejně přijde jíst;
     * kdyby list ukazoval jen součet vybraných, kuchyň by uvařila míň, než kolik lidí dorazí.
     *
     * Seznam dietářů je na listu JEDNOU, ne u každého jídla: omezení se během turnusu nemění,
     * takže patnáct kopií téhož by jen svádělo číst tu, která je zrovna po ruce.
     *
     * ⚠️ **Zjednodušení v1, které je potřeba znát:** počítá se celý turnus, ne „kdo tu je ten den".
     * Zkrácené pobyty (`plannedArrival`/`plannedDeparture`) se vedou jako mlhavý text („po snídani"),
     * ne jako datum, takže se z nich přítomnost v konkrétní den spolehlivě odvodit nedá. Dokud to
     * tak je, je nadhodnocený počet menší zlo než podhodnocený — ale list to musí říct nahlas,
     * ať kuchyň neplánuje podle čísla, které tuhle nuanci nezná.
     */
    public function mealSheetHtml(Event $event): string
    {
        $attendees = $this->loadAttendees($event);
        $ids = array_values(array_filter(array_map(static fn (Participant $p): ?int => $p->getId(), $attendees)));
        if ([] !== $ids) {
            $this->participantRepository->primeAggregationCollections($ids, true); // anti-N+1 na flagy
        }

        // Počty [idJídla][idVarianty] => kolik. Jedna přihláška = nejvýš jedna volba na jídlo
        // (drží unikátní klíč), takže se řádky jen sečtou.
        $counts = [];
        foreach ($this->mealChoiceRepository->findChoiceKeys($ids) as $key) {
            $counts[$key['meal']][$key['variant']] = ($counts[$key['meal']][$key['variant']] ?? 0) + 1;
        }

        $days = [];
        foreach ($this->loadMeals($event) as $meal) {
            $mealId = (int) $meal->getId();
            $vybrano = array_sum($counts[$mealId] ?? []);
            $variants = [];
            foreach ($meal->getVariants() as $variant) {
                if (null !== $variant->getDeletedAt()) {
                    continue; // zrušená varianta se nevaří
                }
                $variants[] = [
                    'name'     => $variant->getName() ?? '',
                    'meatFree' => $variant->isMeatFree(),
                    'count'    => $counts[$mealId][(int) $variant->getId()] ?? 0,
                ];
            }
            $den = $meal->getDate()?->format('Y-m-d') ?? '';
            $days[$den]['date'] = $meal->getDate();
            $days[$den]['meals'][] = [
                'type'      => $meal->getType(),
                'name'      => $meal->getName(),
                // ⚠️ Čas se formátuje TADY, ne v šabloně. `servedFrom`/`servedTo` jsou sloupce typu
                // `time` — okamžik v čase nenesou, jen „půl dvanácté". Twigový filtr `|date` je ale
                // převádí do zobrazovací zóny, takže z 11:30 udělal 12:30 a kuchyň by podle listu
                // vydávala o hodinu později. `format()` na DateTime žádný převod nedělá.
                'servedFrom' => $meal->getServedFrom()?->format('H:i'),
                'servedTo'  => $meal->getServedTo()?->format('H:i'),
                'textValue' => $meal->getTextValue(),
                'variants'  => $variants,
                // Kolik lidí si u tohohle jídla nevybralo. Prázdné varianty = jídlo bez výběru,
                // tam „bez volby" nedává smysl (nebylo z čeho vybírat) → jen celkový počet.
                'noChoice'  => [] === $variants ? null : max(0, count($attendees) - $vybrano),
            ];
        }
        ksort($days);

        return $this->twig->render(self::TPL.'meal-sheet.pdf.html.twig', [
            'eventName' => $event->getName(),
            'total'     => count($attendees),
            'days'      => array_values($days),
            'diets'     => $this->dietRows($attendees),
        ]);
    }

    public function mealSheetPdf(Event $event): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->mealSheetHtml($event),
            false,
            'Kuchyňský list — '.($event->getName() ?? ''),
        );
    }

    /**
     * Jídla turnusu seřazená den → pořadí v rámci dne.
     *
     * Řadit podle `type` v SQL nejde (abecedně by večeře předběhla oběd), proto se druhý klíč
     * bere z `Meal::getTypeOrder()` — tentýž zdroj pravdy jako v aplikaci.
     *
     * @return list<Meal>
     */
    private function loadMeals(Event $event): array
    {
        /** @var list<Meal> $meals */
        $meals = $this->mealRepository->findBy(['event' => $event, 'deletedAt' => null], ['date' => 'ASC']);
        usort($meals, static fn (Meal $a, Meal $b): int => [$a->getDate(), $a->getTypeOrder()]
            <=> [$b->getDate(), $b->getTypeOrder()]);

        return $meals;
    }

    /**
     * Dietáři se jmény a upřesněním — kuchyň potřebuje vědět KDO má zvláštní porci.
     *
     * Název a upřesnění se skládají PO JEDNOTLIVÝCH PŘÍZNACÍCH („bez lepku (celiakie, i stopy)"),
     * ne dva nezávislé seznamy: u člověka se dvěma dietami by se dvě čárkami spojené řady
     * rozpárovaly a upřesnění by se přiřadilo k cizí dietě — na papíře, podle kterého někdo vaří.
     *
     * @param list<Participant> $attendees
     *
     * @return list<array{name: string, diet: string, note: string}>
     */
    private function dietRows(array $attendees): array
    {
        $rows = [];
        foreach ($attendees as $p) {
            $polozky = [];
            $poznamky = [];
            foreach ($p->getParticipantFlags(null, RegistrationFlagCategory::TYPE_FOOD, true) as $flag) {
                $nazev = $flag->getFlag()?->getName();
                $note = trim((string) $flag->getTextValue());
                if (null !== $nazev && '' !== $nazev) {
                    $polozky[] = '' !== $note ? "$nazev ($note)" : $nazev;
                } elseif ('' !== $note) {
                    // Kategorie bez konkrétního příznaku, ale s vyplněným textem („Jiné — detail").
                    $poznamky[] = $note;
                }
            }
            if ([] === $polozky && [] === $poznamky) {
                continue;
            }
            $rows[] = [
                'name' => $p->getContactForRead()?->getName() ?? ('#'.$p->getId()),
                'diet' => implode(', ', $polozky),
                'note' => implode(', ', $poznamky),
            ];
        }

        return $rows;
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
