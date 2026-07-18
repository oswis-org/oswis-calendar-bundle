<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\EventEditType;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\Program\ProgramDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly EntityManagerInterface $em,
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

    /**
     * Úprava jedné aktivity (= pod-událost) z editoru programu. Reuse {@see EventEditType} +
     * {@see WebAdminEventController::edit} vzoru (start/end jsou v formuláři nemapované, přiřadí se
     * ručně). Po uložení zpět na přehled programu (back_url), ne na obecnou stránku události.
     */
    public function editActivity(Request $request, string $eventSlug, int $activityId): Response
    {
        $this->resolveEvent($eventSlug); // 404 když turnus neexistuje
        $activity = $this->em->find(Event::class, $activityId)
            ?? throw $this->createNotFoundException("Aktivita #$activityId nenalezena.");
        $backUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]);

        $form = $this->createForm(EventEditType::class, $activity);
        $form->get('startDate')->setData($activity->getStartDateTime());
        $form->get('endDate')->setData($activity->getEndDateTime());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $start = $form->get('startDate')->getData();
            $end = $form->get('endDate')->getData();
            if ($start instanceof DateTime) {
                $activity->setStartDateTime($start);
            }
            if ($end instanceof DateTime) {
                $activity->setEndDateTime($end);
            }
            $this->em->flush();
            $this->addFlash('success', sprintf('Aktivita „%s" uložena.', $activity->getName() ?? ''));

            return new RedirectResponse($backUrl);
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/event_edit.html.twig', [
            'event'     => $activity,
            'form'      => $form,
            'back_url'  => $backUrl,
            'pageTitle' => sprintf('Upravit aktivitu: %s', $activity->getName() ?? ''),
            'page_title' => sprintf('Upravit aktivitu: %s :: ADMIN', $activity->getName() ?? ''),
        ]);
    }

    /**
     * Přidání nové aktivity (pod-události) do turnusu z editoru programu. Nová událost dostane
     * `superEvent = turnus`; slug se odvodí z názvu, když ho uživatel nevyplní (žádný listener
     * ho negeneruje — viz feedback_app). Po uložení zpět na přehled programu.
     */
    public function newActivity(Request $request, string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $activity = new Event(superEvent: $turnus);
        $backUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]);

        $form = $this->createForm(EventEditType::class, $activity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $start = $form->get('startDate')->getData();
            $end = $form->get('endDate')->getData();
            if ($start instanceof DateTime) {
                $activity->setStartDateTime($start);
            }
            if ($end instanceof DateTime) {
                $activity->setEndDateTime($end);
            }
            if (empty($activity->getSlug())) {
                $activity->updateSlug();
            }
            $this->em->persist($activity);
            $this->em->flush();
            $this->addFlash('success', sprintf('Aktivita „%s" přidána do programu.', $activity->getName() ?? ''));

            return new RedirectResponse($backUrl);
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/event_edit.html.twig', [
            'event'      => $activity,
            'form'       => $form,
            'back_url'   => $backUrl,
            'pageTitle'  => 'Nová aktivita programu',
            'page_title' => 'Nová aktivita programu :: ADMIN',
        ]);
    }

    private function resolveEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
    }
}
