<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Program;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
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
            false,
            'Instruktorský program — ' . ($turnus->getName() ?? ''),
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
