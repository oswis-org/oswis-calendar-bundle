<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\Api;

use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\Document\OperationalDocumentService;
use OswisOrg\OswisCoreBundle\Enum\ExportFormat;
use OswisOrg\OswisCoreBundle\Export\ExportResponseFactory;
use OswisOrg\OswisCoreBundle\Export\ExportResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Kuchyňský list turnusu jako PDF, pro tlačítko v Ionic adminu (vize B7, výstup pro kuchyň).
 *
 * Vzor je {@see ProgramOutputController}: JWT + ROLE_MANAGER, turnus přes `eventId` (to drží
 * Ionic admin) nebo `event` slug, render dělá služba a controller jen routuje.
 *
 * ⚠️ **Ve web-adminu tenhle výstup ZÁMĚRNĚ není.** Celý jídelníček žije v Ionic adminu; druhá
 * cesta ke stažení by znamenala druhé místo, kde se musí udržovat odkaz — a nedávno se ukázalo,
 * jak snadno takový odkaz chybí (route `/jidelnicek` byla týden nasazená bez položky v menu).
 */
#[IsGranted('ROLE_MANAGER')]
final class MealOutputController
{
    public function __construct(
        private readonly OperationalDocumentService $documents,
        private readonly EventRepository $eventRepository,
        private readonly ExportResponseFactory $responseFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        @ini_set('memory_limit', '512M');
        $eventId = $request->query->getInt('eventId');
        $slug = $request->query->getString('event');
        $turnus = $eventId > 0
            ? $this->eventRepository->getEvent([EventRepository::CRITERIA_ID => $eventId])
            : ('' !== $slug ? $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $slug]) : null);
        if (null === $turnus) {
            return new JsonResponse(['error' => 'event_not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->responseFactory->toResponse(new ExportResult(
            'kuchynsky-list_'.date('Y-m-d_His').'.pdf',
            ExportFormat::PDF->mimeType(),
            $this->documents->mealSheetPdf($turnus),
        ));
    }
}
