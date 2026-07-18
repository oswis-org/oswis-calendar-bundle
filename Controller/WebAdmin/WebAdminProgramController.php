<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\Program\ProgramDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Program editor ve web adminu (spec `docs/superpowers/specs/2026-06-12-program-module-design.md`, krok 8) —
 * KROK 1: přehledová stránka `/web_admin/program/{eventSlug}`, na kterou se v dalších slicech navěsí
 * blbuvzdorné formuláře (přidat/upravit/duplikovat den, aktivitu, sekci). Editace = manažerská role
 * (spec: „edit práva vázat na manažerskou roli; běžný člen týmu = čtení + svůj itinerář").
 *
 * Data z {@see ProgramDataService} (read-only pole, ne entity do šablony — per turnus, malý graf).
 * Zápisové operace už existují jako API (STOPA 1.2, calendar v0.2.49), editor je jejich klient.
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminProgramController extends AbstractController
{
    public function __construct(
        private readonly ProgramDataService $programData,
        private readonly EventRepository $eventRepository,
    ) {
    }

    public function index(string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/index.html.twig', [
            'event'     => $turnus,
            'eventSlug' => $eventSlug,
            'tree'      => $this->programData->getProgramTree($turnus),
            'sections'  => $this->programData->getSections($turnus, false),
        ]);
    }

    private function resolveEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
    }
}
