<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\Api;

use OswisOrg\OswisCalendarBundle\Export\ParticipantExportDefinition;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantCategoryService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Enum\ExportFormat;
use OswisOrg\OswisCoreBundle\Export\ExportManager;
use OswisOrg\OswisCoreBundle\Export\ExportRequest;
use OswisOrg\OswisCoreBundle\Export\ExportResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JWT-secured participant export (CSV/PDF) for the Ionic admin and any API client.
 *
 * Scoped per event via the PHP-safe `event` slug param (NOT the dotted
 * `event.id` API filter, which PHP mangles to `event_id` and never matches).
 * Reuses the same repository query the web admin list uses, so the export is
 * bounded and consistent with what admins see. Rendering goes through the
 * shared ExportManager + ParticipantExportDefinition.
 *
 * Query params: `eventId` (int, preferred) nebo `event` (slug),
 * `participantCategory` (slug), `includeDeleted` (bool), `format`
 * (csv|csv-rfc|pdf), `columns[]` (selected column keys).
 */
#[IsGranted('ROLE_MANAGER')]
final class ParticipantExportController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly ParticipantCategoryService $participantCategoryService,
        private readonly ParticipantService $participantService,
        private readonly ExportManager $exportManager,
        private readonly ExportResponseFactory $exportResponseFactory,
        private readonly ParticipantExportDefinition $participantExportDefinition,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Exports can be sizeable (rich Participant graph, eager associations);
        // give this single request headroom over the default pool limit.
        @ini_set('memory_limit', '512M');

        // Prefer the numeric `eventId` (what the Ionic admin has from its route)
        // and fall back to the `event` slug for slug-based API clients.
        $eventId = $request->query->getInt('eventId');
        $eventSlug = $request->query->getString('event');
        $eventCriteria = match (true) {
            $eventId > 0 => [EventRepository::CRITERIA_ID => $eventId],
            '' !== $eventSlug => [EventRepository::CRITERIA_SLUG => $eventSlug],
            default => null,
        };
        $categorySlug = $request->query->getString('participantCategory');
        $event = null !== $eventCriteria
            ? $this->eventService->getRepository()->getEvent($eventCriteria)
            : null;
        $category = '' !== $categorySlug
            ? $this->participantCategoryService->getParticipantTypeBySlug($categorySlug)
            : null;

        $participants = $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => $request->query->getBoolean('includeDeleted'),
            ParticipantRepository::CRITERIA_EVENT => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $category,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
        ]);

        $columnKeys = array_values(array_filter($request->query->all('columns'), 'is_string'));
        $exportRequest = new ExportRequest(
            ExportFormat::fromRequest($request->query->getString('format')),
            [] === $columnKeys ? null : $columnKeys,
        );

        return $this->exportResponseFactory->toResponse(
            $this->exportManager->render($this->participantExportDefinition, $participants, $exportRequest),
        );
    }
}
