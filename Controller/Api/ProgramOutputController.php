<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\Api;

use OswisOrg\OswisCalendarBundle\Service\Program\ProgramOutputResolver;
use OswisOrg\OswisCoreBundle\Enum\ExportFormat;
use OswisOrg\OswisCoreBundle\Export\ExportResponseFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JWT-secured program-output endpoint for the Ionic admin (STOPA 1.3 Fáze 7). Returns the chosen
 * output ({type}) as a PDF/CSV binary. Turnus via `eventId` (preferred, what the Ionic admin
 * holds) or `event` slug; the relevant entity via `id` (dayId / participantId / activityId);
 * `format` (pdf|csv|csv-rfc) for the attendee list. ROLE_MANAGER. Dispatch is shared with the web
 * admin controller via {@see ProgramOutputResolver} — no duplicated rendering logic.
 */
#[IsGranted('ROLE_MANAGER')]
final class ProgramOutputController
{
    public function __construct(
        private readonly ProgramOutputResolver $resolver,
        private readonly ExportResponseFactory $responseFactory,
    ) {
    }

    public function __invoke(Request $request, string $type): Response
    {
        @ini_set('memory_limit', '512M');
        $turnus = $this->resolver->resolveTurnus(
            $request->query->getString('event') ?: null,
            $request->query->getInt('eventId') ?: null,
        );
        if (null === $turnus) {
            return new JsonResponse(['error' => 'event_not_found'], Response::HTTP_NOT_FOUND);
        }
        $id = $request->query->getInt('id');
        $result = $this->resolver->render(
            $type,
            $turnus,
            $id > 0 ? $id : null,
            ExportFormat::fromRequest($request->query->getString('format')),
            date('Y-m-d_His'),
        );
        if (null === $result) {
            return new JsonResponse(['error' => 'output_not_available'], Response::HTTP_NOT_FOUND);
        }

        return $this->responseFactory->toResponse($result);
    }
}
