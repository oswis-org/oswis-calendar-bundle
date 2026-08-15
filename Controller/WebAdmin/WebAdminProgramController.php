<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventGroup;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventSection;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffRole;
use OswisOrg\OswisCalendarBundle\Entity\Event\ProgramDay;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantGroup;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\EventEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\EventSectionEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ProgramDayEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\StaffTeamEditType;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Staff\StaffAssignmentRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\StaffTeamRepository;
use OswisOrg\OswisCalendarBundle\Service\Program\ProgramDataService;
use OswisOrg\OswisCalendarBundle\Service\Program\ProgramReleaseCheck;
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
        private readonly StaffAssignmentRepository $assignmentRepository,
        private readonly ParticipantRepository $participantRepository,
        private readonly EventDuplicateProcessor $duplicateProcessor,
        private readonly StaffTeamRepository $teamRepository,
        private readonly ProgramReleaseCheck $releaseCheck,
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
        // České řazení jmen (Collator cs_CZ) — `strcoll` je v C-locale bytová komparace a hází
        // diakritiku za „z" (viz WebAdminCheckInController / compareParticipants).
        usort($pool, static fn (array $a, array $b): int => StringUtils::compareCzech($a['name'], $b['name']));

        return $pool;
    }

    /**
     * Pásky (ParticipantGroup) pro rotační sloty — hledáme na turnusu i jeho nadřazených událostech
     * (ročník), protože skupiny mohou být definované na rodiči (viz reference_flag_groups_belong_to_parent_offer).
     * 0 pásků = prázdná nabídka (datový úkol týmu); pole targetGroup je code-ready, ne rozbité.
     *
     * @return list<ParticipantGroup>
     */
    private function paseks(Event $turnus): array
    {
        $events = [];
        $event = $turnus;
        for ($i = 0; $i < 6 && null !== $event; $i++) {
            if (null !== $event->getId()) {
                $events[] = $event;
            }
            $event = $event->getSuperEvent();
        }
        if ([] === $events) {
            return [];
        }

        /** @var list<ParticipantGroup> $groups */
        $groups = $this->em->getRepository(ParticipantGroup::class)
            ->createQueryBuilder('g')
            ->andWhere('g.event IN (:events)')
            ->setParameter('events', $events)
            ->orderBy('g.mealOrder', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $groups;
    }

    /**
     * Kategorie „blok programu" ({@see EventCategory::PROGRAM_BLOCK}) — je naseedovaná; defenzivně ji
     * založíme, kdyby na cílovém prostředí chyběla, ať vytvoření bloku nespadne.
     */
    private function resolveBlockCategory(): EventCategory
    {
        $category = $this->em->getRepository(EventCategory::class)
            ->findOneBy(['type' => EventCategory::PROGRAM_BLOCK]);
        if ($category instanceof EventCategory) {
            return $category;
        }
        $category = new EventCategory(new Nameable('Blok programu'), EventCategory::PROGRAM_BLOCK, '#6c757d');
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /**
     * Aktivní bloky (nadakce s kategorií program-block) turnusu — nabídka pro přiřazení/přesun aktivity
     * pod blok v editačním formuláři.
     *
     * @return list<Event>
     */
    private function turnusBlocks(Event $turnus): array
    {
        $blocks = [];
        foreach ($turnus->getSubEvents() as $sub) {
            if (null === $sub->getDeletedAt()
                && EventCategory::PROGRAM_BLOCK === $sub->getCategory()?->getType()) {
                $blocks[] = $sub;
            }
        }

        return $blocks;
    }

    /** Má událost aspoň jednu NEsmazanou podakci? (nadakce/blok → nesmí se vnořit do jiného bloku.) */
    private function hasActiveSubEvents(Event $event): bool
    {
        foreach ($event->getSubEvents() as $sub) {
            if (null === $sub->getDeletedAt()) {
                return true;
            }
        }

        return false;
    }

    public function index(string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/index.html.twig', [
            'event'     => $turnus,
            'eventSlug' => $eventSlug,
            'tree'      => $this->programData->getProgramTree($turnus),
            'sections'  => $this->programData->getSections($turnus, false),
            'kontrola'  => $this->releaseCheck->problemy($turnus),
        ]);
    }

    /**
     * Úprava jedné aktivity (= pod-událost) z editoru programu. Reuse {@see EventEditType} +
     * {@see WebAdminEventController::edit} vzoru (start/end jsou v formuláři nemapované, přiřadí se
     * ručně). Po uložení zpět na přehled programu (back_url), ne na obecnou stránku události.
     */
    public function editActivity(Request $request, string $eventSlug, int $activityId): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $activity = $this->em->find(Event::class, $activityId)
            ?? throw $this->createNotFoundException("Aktivita #$activityId nenalezena.");
        $this->assertBelongsToTurnus($turnus, $activity);
        $backUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]);

        $form = $this->createForm(EventEditType::class, $activity, [
            'groups' => $this->paseks($turnus),
            'blocks' => $this->turnusBlocks($turnus),
        ]);
        $form->get('startDate')->setData($activity->getStartDateTime());
        $form->get('endDate')->setData($activity->getEndDateTime());
        $currentParent = $activity->getSuperEvent();
        if (null !== $currentParent && EventCategory::PROGRAM_BLOCK === $currentParent->getCategory()?->getType()) {
            $form->get('parentBlock')->setData($currentParent); // předvyplní aktuální blok, když je aktivita slotem
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Přesun do/z bloku: nadakci (je program-block nebo má podakce) NELZE vnořit do bloku (MaxDepth 3).
            $targetBlock = $form->get('parentBlock')->getData();
            $nadakceIntoBlock = $targetBlock instanceof Event
                && (EventCategory::PROGRAM_BLOCK === $activity->getCategory()?->getType() || $this->hasActiveSubEvents($activity));
            if ($nadakceIntoBlock) {
                $this->addFlash('danger', 'Blok/nadakci nelze vložit do jiného bloku (má vlastní podakce). Změna neuložena.');
            } else {
                $start = $form->get('startDate')->getData();
                $end = $form->get('endDate')->getData();
                if ($start instanceof DateTime) {
                    $activity->setStartDateTime($start);
                }
                if ($end instanceof DateTime) {
                    $activity->setEndDateTime($end);
                }
                $activity->setSuperEvent($targetBlock instanceof Event ? $targetBlock : $turnus);
                $this->em->flush();
                $this->addFlash('success', sprintf('Aktivita „%s" uložena.', $activity->getName() ?? ''));

                return new RedirectResponse($backUrl);
            }
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
     *
     * Volitelný `?block={id}` zakládá aktivitu jako PODAKCI bloku (rotační slot) — `superEvent = blok`
     * místo turnusu. Blok musí patřit do turnusu (IDOR guard přes {@see assertBelongsToTurnus}).
     */
    public function newActivity(Request $request, string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $block = null;
        $blockId = $request->query->get('block');
        if (is_numeric($blockId)) {
            $block = $this->em->find(Event::class, (int) $blockId)
                ?? throw $this->createNotFoundException("Blok #$blockId nenalezen.");
            $this->assertBelongsToTurnus($turnus, $block);
        }
        // superEvent řídí pole parentBlock (předvyplněné z ?block); default = turnus.
        $activity = new Event(superEvent: $turnus);
        $backUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]);

        $form = $this->createForm(EventEditType::class, $activity, [
            'groups' => $this->paseks($turnus),
            'blocks' => $this->turnusBlocks($turnus),
        ]);
        if (null !== $block) {
            $form->get('parentBlock')->setData($block);
        }
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
            $targetBlock = $form->get('parentBlock')->getData();
            $activity->setSuperEvent($targetBlock instanceof Event ? $targetBlock : $turnus);
            $this->em->persist($activity);
            $this->em->flush();
            $this->addFlash('success', $targetBlock instanceof Event
                ? sprintf('Podakce „%s" přidána do bloku „%s".', $activity->getName() ?? '', $targetBlock->getName() ?? '')
                : sprintf('Aktivita „%s" přidána do programu.', $activity->getName() ?? ''));

            return new RedirectResponse($backUrl);
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/event_edit.html.twig', [
            'event'      => $activity,
            'form'       => $form,
            'back_url'   => $backUrl,
            'pageTitle'  => null !== $block
                ? sprintf('Nová podakce bloku: %s', $block->getName() ?? '')
                : 'Nová aktivita programu',
            'page_title' => 'Nová aktivita programu :: ADMIN',
        ]);
    }

    /**
     * Vytvoření BLOKU programu (nadakce) — Event s kategorií `program-block`, `superEvent = turnus`,
     * vlastní čas NULL (odvozuje se z podakcí přes rekurzivní gettery). Blok sdružuje rotační sloty
     * (aktivita×pásek×čas) nebo série; podakce se do něj přidají tlačítkem „+ podakce"
     * (= newActivity s `?block`). Spec 2.2: „Vytvořit blok s podakcemi", blbuvzdorné, čas odvozený.
     */
    public function newBlock(Request $request, string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $backUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('program_block_new_'.($turnus->getId() ?? 0), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Neplatný CSRF token.');
            }
            $name = trim((string) $request->request->get('name'));
            if ('' === $name) {
                $this->addFlash('danger', 'Zadej název bloku (např. „Dopolední rotace stanovišť").');

                return new RedirectResponse($this->generateUrl(
                    'oswis_org_oswis_calendar_web_admin_program_block_new',
                    ['eventSlug' => $eventSlug],
                ));
            }
            $block = new Event(superEvent: $turnus);
            $block->setName($name);
            $block->setCategory($this->resolveBlockCategory());
            $block->updateSlug();
            $this->em->persist($block);
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'Blok „%s" vytvořen — teď do něj přidej podakce tlačítkem „+ podakce".',
                $name,
            ));

            return new RedirectResponse($backUrl);
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/block_new.html.twig', [
            'eventSlug'  => $eventSlug,
            'turnusId'   => $turnus->getId(),
            'back_url'   => $backUrl,
            'pageTitle'  => 'Nový blok programu',
            'page_title' => 'Nový blok programu :: ADMIN',
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
        $turnus = $this->resolveEvent($eventSlug);
        $day = $this->em->find(ProgramDay::class, $dayId)
            ?? throw $this->createNotFoundException("Den #$dayId nenalezen.");
        $this->assertBelongsToTurnus($turnus, $day->getEvent());

        return $this->handleDayForm($request, $eventSlug, $day, 'Upravit den programu', 'Den uložen.');
    }

    /** Smazání dne programu (aktivity zůstanou — jen se přestanou pod tento den seskupovat). */
    public function deleteDay(Request $request, string $eventSlug, int $dayId): RedirectResponse
    {
        $turnus = $this->resolveEvent($eventSlug);
        $day = $this->em->find(ProgramDay::class, $dayId)
            ?? throw $this->createNotFoundException("Den #$dayId nenalezen.");
        $this->assertBelongsToTurnus($turnus, $day->getEvent());
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
     * Obsazení aktivity (spec 2026-06-12 → StaffAssignment — účastník z týmových přihlášek NEBO
     * externí jméno + role). Týmy (StaffTeam ±) = navazující slice. Ruční přiřazení, žádné auto-návrhy
     * rotace (services se domlouvají v týmu, jen se zapíší — user 2026-06-13).
     */
    public function activityStaff(Request $request, string $eventSlug, int $activityId): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $activity = $this->em->find(Event::class, $activityId)
            ?? throw $this->createNotFoundException("Aktivita #$activityId nenalezena.");
        $this->assertBelongsToTurnus($turnus, $activity);
        $pageUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_activity_staff', ['eventSlug' => $eventSlug, 'activityId' => $activityId]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('program_staff_add_'.$activityId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Neplatný CSRF token.');
            }
            $participantId = (int) $request->request->get('participant');
            $externalName = trim((string) $request->request->get('externalName'));
            $roleId = (int) $request->request->get('role');
            $teamId = (int) $request->request->get('team');
            $participant = $participantId > 0 ? $this->em->find(Participant::class, $participantId) : null;
            $team = $teamId > 0 ? $this->em->find(StaffTeam::class, $teamId) : null;
            $role = $roleId > 0 ? $this->em->find(StaffRole::class, $roleId) : null;
            if (!$participant instanceof Participant && '' === $externalName && !$team instanceof StaffTeam) {
                $this->addFlash('danger', 'Vyber člověka, tým, nebo zadej externí jméno.');

                return new RedirectResponse($pageUrl);
            }
            // Scoping: přiřazovaný účastník i tým musí patřit do turnusu (POST id nesmí obejít scopované selecty).
            if ($participant instanceof Participant) {
                $this->assertBelongsToTurnus($turnus, $participant->getEvent());
            }
            if ($team instanceof StaffTeam) {
                $this->assertBelongsToTurnus($turnus, $team->getEvent());
            }
            // Nový model: obsazení aktivity = StaffAssignment vázaný na aktivitu (activity), scope turnus.
            $assignment = new StaffAssignment('' !== $externalName ? $externalName : null);
            $assignment->setTurnus($turnus);
            $assignment->setActivity($activity);
            $assignment->setRole($role);
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
        foreach ($this->assignmentRepository->getByActivity($activity) as $a) {
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
                'roleLabel' => $a->getRole()?->getName(),
                'kind'      => $kind,
            ];
        }
        $teams = [];
        foreach ($this->teamRepository->getByEvent($turnus) as $t) {
            $teams[] = ['id' => $t->getId(), 'name' => $t->getName() ?? ('#'.$t->getId())];
        }
        // Číselník funkcí použitelných u aktivity (activity/both) pro výběr role.
        $roles = [];
        foreach ($this->em->getRepository(StaffRole::class)->findBy([], ['name' => 'ASC']) as $r) {
            if ($r->isForActivity()) {
                $roles[] = ['id' => $r->getId(), 'name' => $r->getName() ?? ('#'.$r->getId())];
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/activity_staff.html.twig', [
            'eventSlug'   => $eventSlug,
            'activity'    => $activity,
            'assignments' => $assignments,
            'staffPool'   => $this->staffPool($turnus),
            'teams'       => $teams,
            'roles'       => $roles,
            'back_url'    => $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]),
        ]);
    }

    /** Odebrání jednoho obsazení aktivity. */
    public function deleteStaff(Request $request, string $eventSlug, int $activityId, int $assignmentId): RedirectResponse
    {
        $turnus = $this->resolveEvent($eventSlug);
        $assignment = $this->em->find(StaffAssignment::class, $assignmentId)
            ?? throw $this->createNotFoundException("Obsazení #$assignmentId nenalezeno.");
        // IDOR scope: závazek musí patřit do tohoto turnusu.
        if ($assignment->getTurnus()?->getId() !== $turnus->getId()) {
            throw $this->createNotFoundException('Obsazení nepatří k tomuto turnusu.');
        }
        if (!$this->isCsrfTokenValid('program_staff_delete_'.$assignmentId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $assignment->setDeletedAt(new DateTime()); // StaffAssignment má DeletedTrait (soft-delete)
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
        $turnus = $this->resolveEvent($eventSlug);
        $section = $this->em->find(EventSection::class, $sectionId)
            ?? throw $this->createNotFoundException("Sekce #$sectionId nenalezena.");
        $this->assertBelongsToTurnus($turnus, $section->getEvent());

        return $this->handleSectionForm($request, $eventSlug, $section, 'Upravit sekci programu', 'Sekce uložena.');
    }

    /** Smazání informační sekce. */
    public function deleteSection(Request $request, string $eventSlug, int $sectionId): RedirectResponse
    {
        $turnus = $this->resolveEvent($eventSlug);
        $section = $this->em->find(EventSection::class, $sectionId)
            ?? throw $this->createNotFoundException("Sekce #$sectionId nenalezena.");
        $this->assertBelongsToTurnus($turnus, $section->getEvent());
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
        $turnus = $this->resolveEvent($eventSlug);
        $source = $this->em->find(Event::class, $activityId)
            ?? throw $this->createNotFoundException("Aktivita #$activityId nenalezena.");
        $this->assertBelongsToTurnus($turnus, $source);
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
     * Smazání aktivity (pod-události) z programu — SOFT delete přes `setDeletedAt` (Event NENÍ Gedmo
     * SoftDeleteable; `em->remove` by ho smazal natvrdo a spadl na FK, kdyby měl obsazení/přihlášky).
     * Soft-delete zachová vazby (obsazení, přihlášky), je vratné v DB, a `getProgramTree` smazané
     * odfiltruje → z programu i výstupů zmizí. BLOK se maže i s podakcemi (celá rotace); kaskáda
     * projde celý podstrom. IDOR: aktivita musí patřit do turnusu. Spec: „potvrzení u mazání".
     */
    public function deleteActivity(Request $request, string $eventSlug, int $activityId): RedirectResponse
    {
        $turnus = $this->resolveEvent($eventSlug);
        $activity = $this->em->find(Event::class, $activityId)
            ?? throw $this->createNotFoundException("Aktivita #$activityId nenalezena.");
        $this->assertBelongsToTurnus($turnus, $activity);
        if (!$this->isCsrfTokenValid('program_activity_delete_'.$activityId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }

        // Podstrom (podakce bloku…) → soft-delete i děti, ať nezůstanou osiřelé pod smazaným blokem.
        $descendants = [];
        $stack = [$activity];
        for ($guard = 0; [] !== $stack && $guard < 500; $guard++) {
            $node = array_pop($stack);
            foreach ($node->getSubEvents() as $sub) {
                $descendants[] = $sub;
                $stack[] = $sub;
            }
        }
        $name = $activity->getName() ?? '';
        $now = new DateTime();
        foreach ($descendants as $child) {
            $child->setDeletedAt($now);
        }
        $activity->setDeletedAt($now);
        $this->em->flush();

        $this->addFlash('warning', [] !== $descendants
            ? sprintf('Blok „%s" smazán i s %d podakcemi (skryt z programu).', $name, count($descendants))
            : sprintf('Aktivita „%s" smazána (skryta z programu).', $name));

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]));
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
        $turnus = $this->resolveEvent($eventSlug);
        $team = $this->em->find(StaffTeam::class, $teamId) ?? throw $this->createNotFoundException("Tým #$teamId nenalezen.");
        $this->assertBelongsToTurnus($turnus, $team->getEvent());
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
        $turnus = $this->resolveEvent($eventSlug);
        $team = $this->em->find(StaffTeam::class, $teamId) ?? throw $this->createNotFoundException("Tým #$teamId nenalezen.");
        $this->assertBelongsToTurnus($turnus, $team->getEvent());
        if (!$this->isCsrfTokenValid('program_team_member_'.$teamId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->em->find(Participant::class, (int) $request->request->get('participant'));
        if ($participant instanceof Participant) {
            // Scoping: přidávaný člen musí patřit do turnusu (POST id nesmí obejít scopovaný select).
            $this->assertBelongsToTurnus($turnus, $participant->getEvent());
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
        $turnus = $this->resolveEvent($eventSlug);
        $team = $this->em->find(StaffTeam::class, $teamId) ?? throw $this->createNotFoundException("Tým #$teamId nenalezen.");
        $this->assertBelongsToTurnus($turnus, $team->getEvent());
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

    /**
     * Série programu (spec: EventGroup type='program-series' + `sameActivity`; přehled čísluje
     * sameActivity série římsky). EventGroup NEMÁ vazbu na turnus → série se zakládá rovnou z
     * VYBRANÝCH aktivit turnusu (rodí se se svými akcemi → je vždy vázaná na turnus přes ně, žádná
     * prázdná dvojznačná série, žádný cross-turnus). Přiřazení je scopované (každá akce ověřena).
     * ⚠️ Záměrně NE přes sdílený EventEditType (ročníky mají brand-série → filtrovaný select by je smazal).
     */
    public function series(Request $request, string $eventSlug): Response
    {
        $turnus = $this->resolveEvent($eventSlug);
        $seriesUrl = $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_series', ['eventSlug' => $eventSlug]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('program_series_create_'.$turnus->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Neplatný CSRF token.');
            }
            $name = trim((string) $request->request->get('name'));
            $sameActivity = $request->request->getBoolean('sameActivity');
            $validActs = [];
            foreach ($request->request->all('activities') as $rawId) {
                if (!is_numeric($rawId)) {
                    continue;
                }
                $act = $this->em->find(Event::class, (int) $rawId);
                if ($act instanceof Event && $this->isUnderTurnus($turnus->getId(), $act)) {
                    $validActs[] = $act;
                }
            }
            if (count($validActs) < 2) {
                $this->addFlash('danger', 'Vyber aspoň dvě aktivity z tohoto turnusu.');

                return new RedirectResponse($seriesUrl);
            }
            $group = new EventGroup();
            $group->setName('' !== $name ? $name : 'Série');
            $group->setType('program-series');
            $group->setSameActivity($sameActivity);
            if (empty($group->getSlug())) {
                $group->updateSlug();
            }
            $this->em->persist($group);
            foreach ($validActs as $act) {
                $act->setGroup($group);
            }
            $this->em->flush();
            $this->addFlash('success', 'Série vytvořena.');

            return new RedirectResponse($seriesUrl);
        }

        $activities = [];
        $seriesMap = [];
        foreach ($turnus->getSubEvents() as $act) {
            $g = $act->getGroup();
            $gid = ($g instanceof EventGroup && 'program-series' === $g->getType()) ? $g->getId() : null;
            $activities[] = ['id' => $act->getId(), 'name' => $act->getName(), 'start' => $act->getStartDateTimeRecursive()?->format('j.n. H:i'), 'seriesId' => $gid];
            if (null !== $gid && $g instanceof EventGroup) {
                $seriesMap[$gid] ??= ['id' => $gid, 'name' => $g->getName() ?? ('#'.$gid), 'sameActivity' => $g->isSameActivity(), 'activities' => []];
                $seriesMap[$gid]['activities'][] = $act->getName();
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/program/series.html.twig', [
            'eventSlug'  => $eventSlug,
            'turnusId'   => $turnus->getId(),
            'activities' => $activities,
            'series'     => array_values($seriesMap),
            'back_url'   => $this->generateUrl('oswis_org_oswis_calendar_web_admin_program_index', ['eventSlug' => $eventSlug]),
        ]);
    }

    /** Zrušení série (rozpustí ji — akcím se vymaže group; EventGroup se smaže). */
    public function deleteSeries(Request $request, string $eventSlug, int $seriesId): RedirectResponse
    {
        $turnus = $this->resolveEvent($eventSlug);
        $group = $this->em->find(EventGroup::class, $seriesId) ?? throw $this->createNotFoundException("Série #$seriesId nenalezena.");
        $this->assertSeriesBelongsToTurnus($turnus, $group);
        if (!$this->isCsrfTokenValid('program_series_delete_'.$seriesId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        foreach ($group->getEvents() as $ev) {
            if ($ev instanceof Event) {
                $ev->setGroup(null);
            }
        }
        $this->em->remove($group);
        $this->em->flush();
        $this->addFlash('warning', 'Série zrušena.');

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_series', ['eventSlug' => $eventSlug]));
    }

    /** Odebrání jedné aktivity ze série (vymaže její group). */
    public function removeSeriesActivity(Request $request, string $eventSlug, int $activityId): RedirectResponse
    {
        $turnus = $this->resolveEvent($eventSlug);
        $activity = $this->em->find(Event::class, $activityId) ?? throw $this->createNotFoundException("Aktivita #$activityId nenalezena.");
        $this->assertBelongsToTurnus($turnus, $activity);
        if (!$this->isCsrfTokenValid('program_series_remove_'.$activityId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $activity->setGroup(null);
        $this->em->flush();
        $this->addFlash('warning', 'Aktivita odebrána ze série.');

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_program_series', ['eventSlug' => $eventSlug]));
    }

    private function resolveEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
    }

    /**
     * Scoping: dítě (aktivita/den/sekce/tým/assignment) musí patřit do turnusu z URL — jinak by šlo
     * přes `{eventSlug}` jednoho turnusu manipulovat s entitou jiného (IDOR). `$event` je buď entita
     * sama (aktivita = Event, chodíme po superEvent řetězci), nebo její `getEvent()` (den/sekce/tým).
     */
    private function isUnderTurnus(?int $turnusId, ?Event $event, int $depth = 6): bool
    {
        for ($i = 0; $i <= $depth && null !== $event; $i++) {
            if (null !== $turnusId && $event->getId() === $turnusId) {
                return true;
            }
            $event = $event->getSuperEvent();
        }

        return false;
    }

    private function assertBelongsToTurnus(Event $turnus, ?Event $event, int $depth = 6): void
    {
        if (!$this->isUnderTurnus($turnus->getId(), $event, $depth)) {
            throw $this->createNotFoundException('Položka nepatří do tohoto turnusu.');
        }
    }

    /** Série (EventGroup nemá vazbu na turnus) patří k turnusu, když aspoň jedna její akce je pod turnusem. */
    private function assertSeriesBelongsToTurnus(Event $turnus, EventGroup $series): void
    {
        foreach ($series->getEvents() as $ev) {
            if ($ev instanceof Event && $this->isUnderTurnus($turnus->getId(), $ev)) {
                return;
            }
        }
        throw $this->createNotFoundException('Série nepatří do tohoto turnusu.');
    }
}
