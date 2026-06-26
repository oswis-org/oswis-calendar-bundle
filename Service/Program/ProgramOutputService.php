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
        $blocks = ['DOPOLEDNÍ' => [], 'ODPOLEDNÍ' => [], 'VEČERNÍ' => []];
        foreach ($this->programData->getProgramTree($turnus) as $node) {
            if (($node['day']['date'] ?? null) !== $date) {
                continue;
            }
            $dayName = $node['day']['name'] ?? null;
            foreach ($node['activities'] as $activity) {
                if (true !== ($activity['publicInApp'] ?? null)) {
                    continue;
                }
                $start = is_string($activity['start'] ?? null) ? $activity['start'] : '00';
                $hour = (int) substr($start, 0, 2);
                $label = $hour < 12 ? 'DOPOLEDNÍ' : ($hour < 18 ? 'ODPOLEDNÍ' : 'VEČERNÍ');
                $blocks[$label][] = $activity;
            }
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
