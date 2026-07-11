<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Service\Program\ProgramOutputResolver;
use OswisOrg\OswisCoreBundle\Enum\ExportFormat;
use OswisOrg\OswisCoreBundle\Export\ExportResponseFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web admin download endpoints for the program-module outputs (STOPA 1.3 Fáze 7). Pure routing:
 * resolve the turnus (+ relevant entity), let {@see ProgramOutputResolver} render, return the
 * binary as an attachment. ROLE_MANAGER — reading the program for the team. No new PDF code.
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminProgramOutputController extends AbstractController
{
    public function __construct(
        private readonly ProgramOutputResolver $resolver,
        private readonly ExportResponseFactory $responseFactory,
    ) {
    }

    /** Outputs scoped to the whole turnus: instruktorsky / sluzby / itinerar-tym / cely-program. */
    public function turnusOutput(string $type, string $eventSlug): Response
    {
        return $this->respond($type, $eventSlug, null, ExportFormat::PDF);
    }

    public function dayOutput(string $eventSlug, int $dayId): Response
    {
        return $this->respond(ProgramOutputResolver::TYPE_DAY, $eventSlug, $dayId, ExportFormat::PDF);
    }

    public function itineraryOutput(string $eventSlug, int $participantId): Response
    {
        return $this->respond(ProgramOutputResolver::TYPE_ITINERARY, $eventSlug, $participantId, ExportFormat::PDF);
    }

    public function signupOutput(string $eventSlug, int $activityId): Response
    {
        return $this->respond(ProgramOutputResolver::TYPE_SIGNUP, $eventSlug, $activityId, ExportFormat::PDF);
    }

    public function attendeesOutput(string $eventSlug, int $activityId, string $format): Response
    {
        return $this->respond(ProgramOutputResolver::TYPE_ATTENDEES, $eventSlug, $activityId, ExportFormat::fromRequest($format));
    }

    private function respond(string $type, string $eventSlug, ?int $entityId, ExportFormat $format): Response
    {
        @ini_set('memory_limit', '512M');
        $turnus = $this->resolver->resolveTurnus($eventSlug, null);
        if (null === $turnus) {
            throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
        }
        $result = $this->resolver->render($type, $turnus, $entityId, $format, date('Y-m-d_His'));
        if (null === $result) {
            throw $this->createNotFoundException('Výstup nelze vygenerovat (chybí podklad).');
        }

        return $this->responseFactory->toResponse($result);
    }
}
