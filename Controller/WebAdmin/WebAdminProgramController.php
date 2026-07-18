<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventSection;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventStaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Event\ProgramDay;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\EventEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\EventSectionEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ProgramDayEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\StaffTeamEditType;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventStaffAssignmentRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\StaffTeamRepository;
use OswisOrg\OswisCalendarBundle\Service\Program\ProgramDataService;
use OswisOrg\OswisCalendarBundle\State\EventDuplicateProcessor;
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
        private readonly EventDuplicateProcessor $duplicateProcessor,
        private readonly StaffTeamRepository $teamRepository,
    ) {
    }

    /**
     * Staff okruh turnusu pro obsazení / členství týmů = účastníci turnusu mimo běžné „attendee"
     * (organizer/staff/manager…). CRITERIA_PARTICIPANT_TYPE bere jen jeden typ, tak sloučíme přes typy.
     *
     * @return list<array{id: int|null, name: string}>
     */
    private function staffPool(Event $turnus): array
    {
        $pool = [];
        foreach (['organizer', 'staff', 'manager'] as $type) {
            foreach ($this->participantRepository->getParticipants([
                ParticipantRepository::CRITERIA_EVENT                 => $turnus,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 3,
                ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $type,
            ]) as $p) {
                if ($p instanceof Participant && null !== $p->getId()) {
                    $pool[$p->getId()] = ['id' => $p->getId(), 'name' => $this->programData->staffName($p)];
                }
            }
        }
        $pool = array_values($pool);
        usort($pool, static fn (array $a, array $b): int => strcoll($a['name'], $b['name']));

        return $pool;
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
            $teamId = (int) $request->request->get('team');
            $participant = $participantId > 0 ? $this->em->find(Participant::class, $participantId) : null;
            $team = $teamId > 0 ? $this->em->find(StaffTeam::class, $teamId) : null;
            if (!$participant instanceof Participant && '' === $externalName && !$team instanceof StaffTeam) {
                $this->addFlash('danger', 'Vyber člověka, tým, nebo zadej externí jméno.');

                return new RedirectResponse($pageUrl);
            }
            $assignment = new EventStaffAssignment('' !== $externalName ? $externalName : null, '' !== $roleLabel ? $roleLabel : null);
            $assignment->setEvent($activity);
            if ($participant instanceof Participant) {
                $assignment->setParticipant($participant);
            }
            if ($team instanceof StaffTeam) {
                $assignment->setTeam($team);
            }
            $this->em->persist($assignment);
            $this->em->flush();
            $this->addFlash('success', 'Obsazení přidáno.');

            return new RedirectResponse($pageUrl);
        }

        $assignments = [];
        foreach ($this->assignmentRepository->getByEvent($activity) as $a) {
            $participant = $a->getParticipant();
            $team = $a->getTeam();
            if ($participant instanceof Participant) {
                $name = $this->programData->staffName($participant);
                $kind = 'person';
            } elseif ($team instanceof StaffTeam) {
                $name = 'Tým: '.($team->getName() ?? '?');
                $kind = 'team';
            } else {
                $name = (string) $a->getExternalName();
                $kind = 'external';
            }
            $assignments[] = [
                'id'        => $a->getId(),
                'name'      => $name,
                'roleLabel' => $a->getRoleLabel(),
                'kind'      => $kind,
            ];
        }
        $teams = [];
        foreach ($this->teamRepository->getByEvent($turnus) as $t) {
            $teams[] = ['id' => $t->getId(), 'name' => $t->getName() ?? ('#'.$t->getId())];
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/activity_staff.html.twig', [
            'eventSlug'   => $eventSlug,
            'activity'    => $activity,
            'assignments' => $assignments,
            'staffPool'   => $this->staffPool($turnus),
            'teams'       => $teams,
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

    /** Přidání informační sekce (patička/info) k turnusu. */
    public function newSection(Request $request, string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $section = new EventSection();
        $section->setEvent($turnus);

        return $this->handleSectionForm($request, $eventSlug, $section, 'Nová sekce programu', 'Sekce přidána.');
    }

    /** Úprava informační sekce. */
    public function editSection(Request $request, string $eventSlug, int $sectionId): Response
    {
        $this->resolveEvent($eventSlug);
        $section = $this->em->find(EventSection::class, $sectionId)
            ?? throw $this->createNotFoundException("Sekce #$sectionId nenalezena.");

        return $this->handleSectionForm($request, $eventSlug, $section, 'Upravit sekci programu', 'Sekce uložena.');
    }

    /** Smazání informační sekce. */
    public function deleteSection(Request $request, string $eventSlug, int $sectionId): RedirectResponse
    {
        $this->resolveEvent($eventSlug);
        $section = $this->em->find(EventSection::class, $sectionId)
            ?? throw $this->createNotFoundException("Sekce #$sectionId nenalezena.");
        if (!$this->isCsrfTokenValid('program_section_delete_'.$sectionId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $this->em->remove($section);
        $this->em->flush();
        $this->addFlash('warning', 'Sekce smazána.');

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]));
    }

    private function handleSectionForm(Request $request, string $eventSlug, EventSection $section, string $title, string $flash): Response
    {
        $backUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]);
        $form = $this->createForm(EventSectionEditType::class, $section);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (empty($section->getSlug())) {
                $section->updateSlug();
            }
            $this->em->persist($section);
            $this->em->flush();
            $this->addFlash('success', $flash);

            return new RedirectResponse($backUrl);
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/section_edit.html.twig', [
            'form'      => $form,
            'back_url'  => $backUrl,
            'pageTitle' => $title,
        ]);
    }

    /**
     * Duplikace aktivity (spec: vícenásobné sloty = duplikace, žádný recurrence engine). Klon BEZ
     * dětí přes {@see EventDuplicateProcessor::duplicate} (sdíleno s API), pak rovnou na jeho editaci,
     * aby uživatel upravil čas/skupinu.
     */
    public function duplicateActivity(Request $request, string $eventSlug, int $activityId): RedirectResponse
    {
        $this->resolveEvent($eventSlug);
        $source = $this->em->find(Event::class, $activityId)
            ?? throw $this->createNotFoundException("Aktivita #$activityId nenalezena.");
        if (!$this->isCsrfTokenValid('program_activity_duplicate_'.$activityId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $clone = $this->duplicateProcessor->duplicate($source, name: ($source->getName() ?? 'Aktivita').' (kopie)');
        $this->addFlash('success', 'Aktivita zduplikována — uprav čas/skupinu.');

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_program_activity_edit',
            ['eventSlug' => $eventSlug, 'activityId' => $clone->getId()],
        ));
    }

    /**
     * Zveřejnění / skrytí PROGRAMU turnusu účastníkům (žádost usera): přepne `programReleasedAt` na
     * turnusu. Skrytý program se staví a účastníci ho v aplikaci nevidí; tým ho zveřejní až hotový a
     * zkontrolovaný. Brána: {@see \OswisOrg\OswisCalendarBundle\ApiPlatform\EventVisibleToUserExtension}.
     */
    public function toggleProgramRelease(Request $request, string $eventSlug): RedirectResponse
    {
        // Přes em->find (ne getEvent/DQL) — entita z DQL query se s L2 NONSTRICT cache spolehlivě
        // netrackovala pro dirty-update a flush nic nezapsal; find vrátí managed instanci.
        $turnusId = $this->resolveEvent($eventSlug)->getId();
        $turnus = $this->em->find(Event::class, $turnusId)
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
        if (!$this->isCsrfTokenValid('program_release_'.$turnusId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        if ($turnus->isProgramReleased()) {
            $turnus->setProgramReleasedAt(null);
            $this->addFlash('warning', 'Program SKRYT — účastníci ho v aplikaci nevidí.');
        } else {
            $turnus->setProgramReleasedAt(new DateTime());
            $this->addFlash('success', 'Program ZVEŘEJNĚN účastníkům.');
        }
        $this->em->persist($turnus);
        $this->em->flush();

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]));
    }

    /**
     * Týmy/podtýmy turnusu (spec: StaffTeam per turnus, M2M členství). Seznam + založení + správa
     * členů; přiřazení týmu k aktivitě je v obsazení ({@see activityStaff}).
     */
    public function teams(Request $request, string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $backUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]);
        $teamsUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_teams', ['eventSlug' => $eventSlug]);

        $newTeam = new StaffTeam();
        $newTeam->setEvent($turnus);
        $form = $this->createForm(StaffTeamEditType::class, $newTeam);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (empty($newTeam->getSlug())) {
                $newTeam->updateSlug();
            }
            $this->em->persist($newTeam);
            $this->em->flush();
            $this->addFlash('success', 'Tým vytvořen.');

            return new RedirectResponse($teamsUrl);
        }

        $teams = [];
        foreach ($this->teamRepository->getByEvent($turnus) as $t) {
            $members = [];
            foreach ($t->getMembers() as $m) {
                $members[] = ['id' => $m->getId(), 'name' => $this->programData->staffName($m)];
            }
            $teams[] = ['id' => $t->getId(), 'name' => $t->getName() ?? ('#'.$t->getId()), 'members' => $members];
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/teams.html.twig', [
            'eventSlug' => $eventSlug,
            'teams'     => $teams,
            'staffPool' => $this->staffPool($turnus),
            'form'      => $form,
            'back_url'  => $backUrl,
        ]);
    }

    public function deleteTeam(Request $request, string $eventSlug, int $teamId): RedirectResponse
    {
        $this->resolveEvent($eventSlug);
        $team = $this->em->find(StaffTeam::class, $teamId) ?? throw $this->createNotFoundException("Tým #$teamId nenalezen.");
        if (!$this->isCsrfTokenValid('program_team_delete_'.$teamId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $this->em->remove($team);
        $this->em->flush();
        $this->addFlash('warning', 'Tým smazán.');

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_teams', ['eventSlug' => $eventSlug]));
    }

    public function addTeamMember(Request $request, string $eventSlug, int $teamId): RedirectResponse
    {
        $this->resolveEvent($eventSlug);
        $team = $this->em->find(StaffTeam::class, $teamId) ?? throw $this->createNotFoundException("Tým #$teamId nenalezen.");
        if (!$this->isCsrfTokenValid('program_team_member_'.$teamId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->em->find(Participant::class, (int) $request->request->get('participant'));
        if ($participant instanceof Participant) {
            $team->addMember($participant);
            $this->em->flush();
            $this->addFlash('success', 'Člen přidán do týmu.');
        } else {
            $this->addFlash('danger', 'Vyber člověka ze seznamu.');
        }

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_teams', ['eventSlug' => $eventSlug]));
    }

    public function removeTeamMember(Request $request, string $eventSlug, int $teamId, int $participantId): RedirectResponse
    {
        $this->resolveEvent($eventSlug);
        $team = $this->em->find(StaffTeam::class, $teamId) ?? throw $this->createNotFoundException("Tým #$teamId nenalezen.");
        if (!$this->isCsrfTokenValid('program_team_member_remove_'.$teamId.'_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->em->find(Participant::class, $participantId);
        if ($participant instanceof Participant) {
            $team->removeMember($participant);
            $this->em->flush();
            $this->addFlash('warning', 'Člen odebrán z týmu.');
        }

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_teams', ['eventSlug' => $eventSlug]));
    }

    private function resolveEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
    }
}
