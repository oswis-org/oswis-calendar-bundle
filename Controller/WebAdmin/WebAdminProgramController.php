<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventStaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Event\ProgramDay;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\EventEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ProgramDayEditType;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventStaffAssignmentRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
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
        private readonly EventStaffAssignmentRepository $assignmentRepository,
        private readonly ParticipantRepository $participantRepository,
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

    /** Přidání dne programu (label + datum) do turnusu; datum seskupí aktivity v přehledu. */
    public function newDay(Request $request, string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $day = new ProgramDay();
        $day->setEvent($turnus);

        return $this->handleDayForm($request, $eventSlug, $day, 'Nový den programu', 'Den přidán.');
    }

    /** Úprava dne programu. */
    public function editDay(Request $request, string $eventSlug, int $dayId): Response
    {
        $this->resolveEvent($eventSlug);
        $day = $this->em->find(ProgramDay::class, $dayId)
            ?? throw $this->createNotFoundException("Den #$dayId nenalezen.");

        return $this->handleDayForm($request, $eventSlug, $day, 'Upravit den programu', 'Den uložen.');
    }

    /** Smazání dne programu (aktivity zůstanou — jen se přestanou pod tento den seskupovat). */
    public function deleteDay(Request $request, string $eventSlug, int $dayId): RedirectResponse
    {
        $this->resolveEvent($eventSlug);
        $day = $this->em->find(ProgramDay::class, $dayId)
            ?? throw $this->createNotFoundException("Den #$dayId nenalezen.");
        if (!$this->isCsrfTokenValid('program_day_delete_'.$dayId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $this->em->remove($day);
        $this->em->flush();
        $this->addFlash('warning', 'Den programu smazán.');

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]));
    }

    private function handleDayForm(Request $request, string $eventSlug, ProgramDay $day, string $title, string $flash): Response
    {
        $backUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]);
        $form = $this->createForm(ProgramDayEditType::class, $day);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (empty($day->getSlug())) {
                $day->updateSlug();
            }
            $this->em->persist($day);
            $this->em->flush();
            $this->addFlash('success', $flash);

            return new RedirectResponse($backUrl);
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/day_edit.html.twig', [
            'form'      => $form,
            'back_url'  => $backUrl,
            'pageTitle' => $title,
        ]);
    }

    /**
     * Obsazení aktivity (spec 2026-06-12: EventStaffAssignment — účastník z týmových přihlášek NEBO
     * externí jméno + role). Týmy (StaffTeam ±) = navazující slice. Ruční přiřazení, žádné auto-návrhy
     * rotace (services se domlouvají v týmu, jen se zapíší — user 2026-06-13).
     */
    public function activityStaff(Request $request, string $eventSlug, int $activityId): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $activity = $this->em->find(Event::class, $activityId)
            ?? throw $this->createNotFoundException("Aktivita #$activityId nenalezena.");
        $pageUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_activity_staff', ['eventSlug' => $eventSlug, 'activityId' => $activityId]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('program_staff_add_'.$activityId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Neplatný CSRF token.');
            }
            $participantId = (int) $request->request->get('participant');
            $externalName = trim((string) $request->request->get('externalName'));
            $roleLabel = trim((string) $request->request->get('roleLabel'));
            $participant = $participantId > 0 ? $this->em->find(Participant::class, $participantId) : null;
            if (!$participant instanceof Participant && '' === $externalName) {
                $this->addFlash('danger', 'Vyber člověka ze seznamu, nebo zadej externí jméno.');

                return new RedirectResponse($pageUrl);
            }
            $assignment = new EventStaffAssignment('' !== $externalName ? $externalName : null, '' !== $roleLabel ? $roleLabel : null);
            $assignment->setEvent($activity);
            if ($participant instanceof Participant) {
                $assignment->setParticipant($participant);
            }
            $this->em->persist($assignment);
            $this->em->flush();
            $this->addFlash('success', 'Obsazení přidáno.');

            return new RedirectResponse($pageUrl);
        }

        $assignments = [];
        foreach ($this->assignmentRepository->getByEvent($activity) as $a) {
            $participant = $a->getParticipant();
            $assignments[] = [
                'id'        => $a->getId(),
                'name'      => $participant instanceof Participant ? $this->programData->staffName($participant) : (string) $a->getExternalName(),
                'roleLabel' => $a->getRoleLabel(),
                'external'  => !$participant instanceof Participant,
            ];
        }
        $staffPool = [];
        foreach ($this->participantRepository->getParticipants([
            ParticipantRepository::CRITERIA_EVENT                 => $turnus,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 3,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => 'staff',
        ]) as $p) {
            if ($p instanceof Participant) {
                $staffPool[] = ['id' => $p->getId(), 'name' => $this->programData->staffName($p)];
            }
        }
        usort($staffPool, static fn (array $a, array $b): int => strcoll($a['name'], $b['name']));

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/activity_staff.html.twig', [
            'eventSlug'   => $eventSlug,
            'activity'    => $activity,
            'assignments' => $assignments,
            'staffPool'   => $staffPool,
            'back_url'    => $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]),
        ]);
    }

    /** Odebrání jednoho obsazení aktivity. */
    public function deleteStaff(Request $request, string $eventSlug, int $activityId, int $assignmentId): RedirectResponse
    {
        $this->resolveEvent($eventSlug);
        $assignment = $this->em->find(EventStaffAssignment::class, $assignmentId)
            ?? throw $this->createNotFoundException("Obsazení #$assignmentId nenalezeno.");
        if (!$this->isCsrfTokenValid('program_staff_delete_'.$assignmentId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $this->em->remove($assignment);
        $this->em->flush();
        $this->addFlash('warning', 'Obsazení odebráno.');

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_activity_staff', ['eventSlug' => $eventSlug, 'activityId' => $activityId]));
    }

    private function resolveEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
    }
}
