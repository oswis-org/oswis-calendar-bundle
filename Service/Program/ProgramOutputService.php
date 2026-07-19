<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Program;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCoreBundle\Service\ExportService;
use Twig\Environment;

/**
 * Renders the program-module outputs (STOPA 1.3) as PDF via the core ExportService
 * (branded header/footer, mPDF). Data comes from {@see ProgramDataService}; Twig templates
 * live in Resources/views/web_admin/program/. Each method exposes an `…Html` variant so the
 * HTML can be asserted in tests without rendering the full PDF.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md sekce 2.4 / krok 11.
 */
final class ProgramOutputService
{
    private const string TPL = '@OswisOrgOswisCalendar/web_admin/program/';

    /** Sign-up sheet: extra blank lines past capacity (latecomers / corrections). */
    private const int SIGNUP_RESERVE = 4;

    /** Sign-up sheet: rows when the activity has no capacity set. */
    private const int SIGNUP_DEFAULT_ROWS = 30;

    public function __construct(
        private readonly ProgramDataService $programData,
        private readonly Environment $twig,
        private readonly ExportService $exportService,
    ) {
    }

    /** Full internal team program (à la 2TURNUS_INSTRUKTORSKY.pdf). */
    public function instructorProgramHtml(Event $turnus): string
    {
        $tree = $this->programData->getProgramTree($turnus);
        $sheets = [];
        $prices = [];
        foreach ($tree as $node) {
            foreach ($node['activities'] as $activity) {
                $this->collectSheetsAndPrices($activity, $sheets, $prices);
            }
        }

        return $this->twig->render(self::TPL . 'instructor-program.pdf.html.twig', [
            'turnusName' => $turnus->getName(),
            'tree' => $tree,
            'matrix' => $this->programData->getStaffMatrix($turnus),
            'sections' => $this->programData->getSections($turnus, false),
            'sheets' => $sheets,
            'prices' => $prices,
        ]);
    }

    public function instructorProgramPdf(Event $turnus): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->instructorProgramHtml($turnus),
            true,
            'Instruktorský program — ' . ($turnus->getName() ?? ''),
        );
    }

    /**
     * Participant daily programme (à la 3.den.pdf) — one day, public activities only,
     * grouped into morning/afternoon/evening blocks. `$date` = 'Y-m-d'.
     */
    public function participantDayHtml(Event $turnus, string $date): string
    {
        $dayName = null;
        $blocks = $this->emptyBlocks();
        foreach ($this->programData->getProgramTree($turnus) as $node) {
            if (($node['day']['date'] ?? null) !== $date) {
                continue;
            }
            $dayName = $node['day']['name'] ?? null;
            $blocks = $this->groupIntoBlocks($node['activities']);
        }

        return $this->twig->render(self::TPL . 'participant-day.pdf.html.twig', [
            'turnusName' => $turnus->getName(),
            'date' => $date,
            'dayName' => $dayName,
            'blocks' => $blocks,
        ]);
    }

    public function participantDayPdf(Event $turnus, string $date): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->participantDayHtml($turnus, $date),
            false,
            'Denní program ' . $date,
        );
    }

    /**
     * Whole-turnus participant programme — every day's public activities as one continuous
     * document (DRŽÍME — user 2026-06-13). Days with no public activity are skipped.
     */
    public function participantFullProgramHtml(Event $turnus): string
    {
        $days = [];
        foreach ($this->programData->getProgramTree($turnus) as $node) {
            if (null === ($node['day'] ?? null)) {
                continue;
            }
            $blocks = $this->groupIntoBlocks($node['activities']);
            if ([] === array_filter($blocks, static fn (array $list): bool => [] !== $list)) {
                continue;
            }
            $days[] = [
                'date' => $node['day']['date'] ?? null,
                'name' => $node['day']['name'] ?? null,
                'blocks' => $blocks,
            ];
        }

        return $this->twig->render(self::TPL . 'participant-full.pdf.html.twig', [
            'turnusName' => $turnus->getName(),
            'days' => $days,
        ]);
    }

    public function participantFullProgramPdf(Event $turnus): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->participantFullProgramHtml($turnus),
            false,
            'Program — ' . ($turnus->getName() ?? ''),
        );
    }

    /**
     * Empty numbered sign-up sheet for an activity (à la "první pomoc ARCH") — pinned on a board,
     * people write their own names. Row count = capacity + reserve, default 30. NOT an attendance
     * list (that is {@see SubEventAttendanceExportDefinition}).
     */
    public function signupSheetHtml(Event $activity): string
    {
        $capacity = $activity->getFullCapacity() ?? $activity->getBaseCapacity();
        $rowCount = null !== $capacity && $capacity > 0
            ? $capacity + self::SIGNUP_RESERVE
            : self::SIGNUP_DEFAULT_ROWS;
        $category = $activity->getCategory()?->getName();
        $title = (null !== $category && '' !== $category ? $category . ': ' : '') . ($activity->getName() ?? '');

        return $this->twig->render(self::TPL . 'signup-sheet.pdf.html.twig', [
            'title' => $title,
            'date' => $activity->getStartDateTimeRecursive()?->format('Y-m-d'),
            'rowCount' => $rowCount,
        ]);
    }

    public function signupSheetPdf(Event $activity): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->signupSheetHtml($activity),
            false,
            'Zápisový arch — ' . ($activity->getName() ?? ''),
        );
    }

    /** @return array{DOPOLEDNÍ: list<mixed>, ODPOLEDNÍ: list<mixed>, VEČERNÍ: list<mixed>} */
    private function emptyBlocks(): array
    {
        return ['DOPOLEDNÍ' => [], 'ODPOLEDNÍ' => [], 'VEČERNÍ' => []];
    }

    /**
     * Group a day's activities into morning/afternoon/evening blocks (public activities only).
     *
     * @param list<array<string, mixed>> $activities
     *
     * @return array{DOPOLEDNÍ: list<mixed>, ODPOLEDNÍ: list<mixed>, VEČERNÍ: list<mixed>}
     */
    private function groupIntoBlocks(array $activities): array
    {
        $blocks = $this->emptyBlocks();
        foreach ($this->flattenBlocks($activities) as $activity) {
            if (true !== ($activity['publicInApp'] ?? null)) {
                continue;
            }
            $start = is_string($activity['start'] ?? null) ? $activity['start'] : '00';
            $hour = (int) substr($start, 0, 2);
            if ($hour < 12) {
                $blocks['DOPOLEDNÍ'][] = $activity;
            } elseif ($hour < 18) {
                $blocks['ODPOLEDNÍ'][] = $activity;
            } else {
                $blocks['VEČERNÍ'][] = $activity;
            }
        }
        // Po zploštění rotací se sloty (různé časy) prokládají s ostatními aktivitami → seřadit po čase.
        // usort je v PHP 8 stabilní, takže stejný čas zachová vstupní pořadí (např. pořadí stanovišť).
        foreach ($blocks as &$list) {
            usort($list, static function (mixed $x, mixed $y): int {
                $xs = is_array($x) && is_string($x['start'] ?? null) ? $x['start'] : '99';
                $ys = is_array($y) && is_string($y['start'] ?? null) ? $y['start'] : '99';

                return $xs <=> $ys;
            });
        }
        unset($list);

        return $blocks;
    }

    /**
     * Rozbalí bloky (nadakce) na jejich sloty — účastnický program ukazuje rotaci PLOŠE po časech
     * (jeden slot = jeden řádek s vlastním časem a páskem, à la 3.den.pdf), NE blok jako jeden bod;
     * blok samotný je interní kontejner (publicInApp=false), takže se nevykresluje. Ostatní aktivity
     * projdou beze změny. Sloty nesou vlastní publicInApp/start/place… ({@see ProgramDataService}).
     *
     * @param  list<array<string, mixed>>  $activities
     * @return list<array<mixed>>
     */
    private function flattenBlocks(array $activities): array
    {
        $out = [];
        foreach ($activities as $activity) {
            $subs = is_array($activity['subActivities'] ?? null) ? $activity['subActivities'] : [];
            if (true === ($activity['isBlock'] ?? false) && [] !== $subs) {
                foreach ($subs as $slot) {
                    if (is_array($slot)) {
                        $out[] = $slot;
                    }
                }
                continue;
            }
            $out[] = $activity;
        }

        return $out;
    }

    /** Personal itinerary for one instructor ("kde mám být") — their slots, chronological. */
    public function instructorItineraryHtml(Event $turnus, Participant $instructor): string
    {
        return $this->twig->render(self::TPL . 'itinerary-personal.pdf.html.twig', [
            'turnusName' => $turnus->getName(),
            'instructorName' => $this->programData->staffName($instructor),
            'rows' => $this->programData->getInstructorItinerary($turnus, $instructor),
        ]);
    }

    public function instructorItineraryPdf(Event $turnus, Participant $instructor): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->instructorItineraryHtml($turnus, $instructor),
            false,
            'Itinerář — ' . $this->programData->staffName($instructor),
        );
    }

    /** Whole-team overview — every staff person (sub-teams expanded) with their slots. */
    public function teamOverviewHtml(Event $turnus): string
    {
        return $this->twig->render(self::TPL . 'itinerary-team-overview.pdf.html.twig', [
            'turnusName' => $turnus->getName(),
            'people' => $this->programData->getTeamOverview($turnus),
        ]);
    }

    public function teamOverviewPdf(Event $turnus): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->teamOverviewHtml($turnus),
            false,
            'Přehled týmu — ' . ($turnus->getName() ?? ''),
        );
    }

    /** Service roster matrix (à la SLUŽBY 2.TURNUS) — date × service category. */
    public function serviceRosterHtml(Event $turnus): string
    {
        $matrix = $this->programData->getStaffMatrix($turnus);
        $categories = [];
        foreach ($matrix as $byCategory) {
            foreach (array_keys($byCategory) as $category) {
                $categories[$category] = true;
            }
        }
        $categories = array_keys($categories);
        sort($categories);

        return $this->twig->render(self::TPL . 'service-roster.pdf.html.twig', [
            'turnusName' => $turnus->getName(),
            'matrix' => $matrix,
            'categories' => $categories,
        ]);
    }

    public function serviceRosterPdf(Event $turnus): string
    {
        return $this->exportService->getPdfFromHtml(
            $this->serviceRosterHtml($turnus),
            true,
            'Rozpis služeb — ' . ($turnus->getName() ?? ''),
        );
    }

    /**
     * @param array<array-key, mixed> $activity
     * @param list<array<array-key, mixed>> $sheets
     * @param list<array<array-key, mixed>> $prices
     */
    private function collectSheetsAndPrices(array $activity, array &$sheets, array &$prices): void
    {
        if (null !== ($activity['fullCapacity'] ?? null) || null !== ($activity['baseCapacity'] ?? null)) {
            $sheets[] = $activity;
        }
        if (null !== ($activity['price'] ?? null)) {
            $prices[] = $activity;
        }
        $subActivities = $activity['subActivities'] ?? [];
        if (is_array($subActivities)) {
            foreach ($subActivities as $sub) {
                if (is_array($sub)) {
                    $this->collectSheetsAndPrices($sub, $sheets, $prices);
                }
            }
        }
    }
}
