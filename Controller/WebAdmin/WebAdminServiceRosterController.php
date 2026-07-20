<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffRole;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\StaffAssignmentEditType;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\StaffTeamRepository;
use OswisOrg\OswisCalendarBundle\Repository\Staff\StaffAssignmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ROZPIS SLUŽEB ve web adminu — mřížka dny × funkce nad {@see StaffAssignment} bez aktivity.
 *
 * Proč i na webu, když existuje Ionic editor: rozpis se **připravuje před akcí v klidu u počítače**
 * (Ionic je terénní nástroj na schůzi). Dosud šel rozpis z webu jen VYTISKNOUT
 * (`service-roster.pdf`), ne prohlédnout či upravit.
 *
 * Data i sémantika jsou sdílené s API/Ionicem (tatáž entita, tytéž validace) — tohle je jen druhá
 * vstupní cesta, ne druhý model.
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminServiceRosterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
        private readonly StaffAssignmentRepository $assignmentRepository,
        private readonly ParticipantRepository $participantRepository,
        private readonly StaffTeamRepository $teamRepository,
    ) {
    }

    public function index(string $eventSlug): Response
    {
        $turnus = $this->resolveTurnus($eventSlug);
        $assignments = $this->assignmentRepository->getServicesByTurnus($turnus);

        // Mřížka: [ISO den][id funkce] => list směn (klíč 0 = závazek bez funkce, ať se neztratí).
        $grid = [];
        foreach ($assignments as $assignment) {
            $start = $assignment->getEffectiveStart();
            if (!$start instanceof DateTimeInterface) {
                continue;
            }
            $grid[$start->format('Y-m-d')][(int) $assignment->getRole()?->getId()][] = $assignment;
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/service_roster/index.html.twig', [
            'turnus'     => $turnus,
            'eventSlug'  => $eventSlug,
            'days'       => $this->turnusDays($turnus),
            'roles'      => $this->serviceRoles(),
            'grid'       => $grid,
            'total'      => count($assignments),
            'pageTitle'  => 'Rozpis služeb — '.($turnus->getShortName() ?? $turnus->getName() ?? ''),
            'page_title' => 'Rozpis služeb :: ADMIN',
        ]);
    }

    public function new(Request $request, string $eventSlug): Response
    {
        $turnus = $this->resolveTurnus($eventSlug);
        $assignment = new StaffAssignment();
        $assignment->setTurnus($turnus);
        // Předvyplnění z mřížky (klik na buňku): den + funkce.
        $day = (string) $request->query->get('day', '');
        $roleId = (int) $request->query->get('role', 0);
        if ('' !== $day && false !== strtotime($day)) {
            $assignment->setStartDateTime(new DateTime($day.' 06:00'));
            $assignment->setEndDateTime(new DateTime($day.' 12:00'));
        }
        if ($roleId > 0) {
            $assignment->setRole($this->em->find(StaffRole::class, $roleId));
        }

        return $this->handleForm($request, $turnus, $assignment, $eventSlug, 'Služba zapsána.');
    }

    public function edit(Request $request, string $eventSlug, int $id): Response
    {
        $turnus = $this->resolveTurnus($eventSlug);
        $assignment = $this->resolveAssignment($turnus, $id);

        return $this->handleForm($request, $turnus, $assignment, $eventSlug, 'Služba upravena.');
    }

    /** Odebrání služby z rozpisu — soft-delete (StaffAssignment má `DeletedTrait`). */
    public function delete(Request $request, string $eventSlug, int $id): RedirectResponse
    {
        $turnus = $this->resolveTurnus($eventSlug);
        $assignment = $this->resolveAssignment($turnus, $id);
        if (!$this->isCsrfTokenValid('service_roster_delete_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Neplatný token, nic se nesmazalo.');

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_service_roster', ['eventSlug' => $eventSlug]);
        }
        $assignment->setDeletedAt(new DateTime());
        $this->em->flush();
        $this->addFlash('success', 'Služba odebrána z rozpisu.');

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_service_roster', ['eventSlug' => $eventSlug]);
    }

    private function handleForm(
        Request $request,
        Event $turnus,
        StaffAssignment $assignment,
        string $eventSlug,
        string $successMessage,
    ): Response {
        $form = $this->createForm(StaffAssignmentEditType::class, $assignment, [
            'roles'      => $this->serviceRoles(),
            'staff_pool' => $this->staffPool($turnus),
            'teams'      => $this->teamRepository->getByEvent($turnus),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Služba = závazek BEZ aktivity; turnus drží scope (obojí nastavujeme my, ne formulář).
            $assignment->setActivity(null);
            $assignment->setTurnus($turnus);
            $this->em->persist($assignment);
            $this->em->flush();
            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_service_roster', ['eventSlug' => $eventSlug]);
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/service_roster/edit.html.twig', [
            'form'       => $form->createView(),
            'turnus'     => $turnus,
            'eventSlug'  => $eventSlug,
            'assignment' => $assignment,
            'pageTitle'  => null === $assignment->getId() ? 'Nová služba' : 'Úprava služby',
            'page_title' => 'Rozpis služeb :: ADMIN',
        ]);
    }

    /** Funkce použitelné jako celodenní SLUŽBA (`appliesTo` service/both; prázdná hodnota = všude). */
    private function serviceRoles(): array
    {
        $roles = [];
        foreach ($this->em->getRepository(StaffRole::class)->findBy([], ['name' => 'ASC']) as $role) {
            if ($role->isForService()) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Pool týmu = účastníci turnusu i jeho NADŘAZENÉ akce mimo běžné „attendee" — tým bývá
     * registrovaný na rodičovské akci (turnusy jsou její pod-akce), takže scope jen na turnus by ho minul.
     *
     * @return list<Participant>
     */
    private function staffPool(Event $turnus): array
    {
        $scope = $turnus->getSuperEvent() ?? $turnus;
        $pool = [];
        foreach (['organizer', 'staff', 'manager'] as $type) {
            foreach ($this->participantRepository->getParticipants([
                ParticipantRepository::CRITERIA_EVENT                 => $scope,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 3,
                ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $type,
            ]) as $participant) {
                if ($participant instanceof Participant && null !== $participant->getId()) {
                    $pool[$participant->getId()] = $participant;
                }
            }
        }

        return array_values($pool);
    }

    /**
     * Dny turnusu (ISO) — od začátku do konce včetně. Strop 60 dní je pojistka proti nesmyslnému
     * rozsahu v datech (nechceme vyrenderovat tisíce sloupců).
     *
     * @return list<string>
     */
    private function turnusDays(Event $turnus): array
    {
        $start = $turnus->getStartDateTimeRecursive();
        if (!$start instanceof DateTimeInterface) {
            return [];
        }
        $end = $turnus->getEndDateTimeRecursive() ?? $start;
        $cursor = new DateTime($start->format('Y-m-d').' 12:00');
        $last = new DateTime($end->format('Y-m-d').' 12:00');
        $days = [];
        while ($cursor <= $last && count($days) < 60) {
            $days[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }

        return $days;
    }

    private function resolveTurnus(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
    }

    /**
     * Scoping proti IDOR: závazek z URL MUSÍ patřit turnusu z URL — jinak by šlo přes slug jednoho
     * turnusu upravovat/maza cizí rozpis.
     */
    private function resolveAssignment(Event $turnus, int $id): StaffAssignment
    {
        $assignment = $this->em->find(StaffAssignment::class, $id);
        if (!$assignment instanceof StaffAssignment || null !== $assignment->getDeletedAt()) {
            throw $this->createNotFoundException('Služba nenalezena.');
        }
        if ($assignment->getTurnus()?->getId() !== $turnus->getId()) {
            throw $this->createNotFoundException('Služba nepatří do tohoto turnusu.');
        }

        return $assignment;
    }
}
