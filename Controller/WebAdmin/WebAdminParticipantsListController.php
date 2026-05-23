<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Closure;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Aggregations\EventAggregationsService;
use OswisOrg\OswisCalendarBundle\Service\Event\EventSeriesService;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantCategoryService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationOfferService;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment;

class WebAdminParticipantsListController extends AbstractController
{
    public const string FILTER_ALL            = 'all';
    public const string FILTER_UNPAID         = 'unpaid';
    public const string FILTER_UNPAID_DEPOSIT = 'unpaid-deposit';
    public const string FILTER_OVERPAID       = 'overpaid';
    public const string FILTER_FOOD           = 'food';

    public function __construct(
        public EventService $eventService,
        public ParticipantService $participantService,
        public ParticipantCategoryService $participantCategoryService,
        public RegistrationOfferService $participantRegistrationService,
        public EntityManagerInterface $em,
        public EventSeriesService $eventSeriesService,
        public EventAggregationsService $eventAggregationsService,
    ) {
    }

    public function showParticipants(?string $eventSlug = null, ?string $participantCategorySlug = null): Response
    {
        return $this->renderFiltered(
            $eventSlug, $participantCategorySlug,
            self::FILTER_ALL, 'Přehled přihlášek :: ADMIN',
            static fn (Participant $p) => true,
        );
    }

    public function showUnpaidParticipants(?string $eventSlug = null, ?string $participantCategorySlug = null): Response
    {
        return $this->renderFiltered(
            $eventSlug, $participantCategorySlug,
            self::FILTER_UNPAID, 'Přehled nezaplacených účastníků :: ADMIN',
            static fn (Participant $p) => $p->getRemainingPrice() > 0,
        );
    }

    public function showUnpaidDepositParticipants(?string $eventSlug = null, ?string $participantCategorySlug = null): Response
    {
        return $this->renderFiltered(
            $eventSlug, $participantCategorySlug,
            self::FILTER_UNPAID_DEPOSIT, 'Přehled přihlášek s nezaplacenou zálohou :: ADMIN',
            static fn (Participant $p) => $p->getRemainingDeposit() > 0,
        );
    }

    public function showOverpaidParticipants(?string $eventSlug = null, ?string $participantCategorySlug = null): Response
    {
        return $this->renderFiltered(
            $eventSlug, $participantCategorySlug,
            self::FILTER_OVERPAID, 'Přehled přeplacených účastníků :: ADMIN',
            static fn (Participant $p) => $p->getRemainingPrice() < 0,
        );
    }

    public function showFoodParticipants(?string $eventSlug = null, ?string $participantCategorySlug = null): Response
    {
        return $this->renderFiltered(
            $eventSlug, $participantCategorySlug,
            self::FILTER_FOOD, 'Přehled účastníků se stravovacím omezením :: ADMIN',
            static fn (Participant $p) => $p->getParticipantFlags(null, RegistrationFlagCategory::TYPE_FOOD, true)->count() > 0,
        );
    }

    /**
     * Render the participant list scoped to event + category and filtered by the given
     * predicate, with the five-tab nav (Vše / Nezaplacení / Nezaplacená záloha /
     * Přeplacení / Stravovací omezení) so admins can switch between views without
     * re-typing URLs.
     *
     * @param Closure(Participant): bool $filterPredicate
     */
    private function renderFiltered(
        ?string $eventSlug,
        ?string $participantCategorySlug,
        string $activeFilter,
        string $title,
        Closure $filterPredicate,
    ): Response {
        $participantCategory = $this->participantCategoryService->getParticipantTypeBySlug($participantCategorySlug);
        $event = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);

        $participants = $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_INCLUDE_DELETED       => true,
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY  => $participantCategory,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
        ])->filter($filterPredicate);

        $participantsArray = $participants->toArray();
        usort($participantsArray, static fn (Participant $a, Participant $b) => strcoll($a->getSortableName(), $b->getSortableName()));

        return $this->render("@OswisOrgOswisCalendar/web_admin/participants.html.twig", [
            'participantCategory' => $participantCategory,
            'event'               => $event,
            'participants'        => new ArrayCollection($participantsArray),
            'title'               => $title,
            'filterTabs'          => $this->buildFilterTabs($eventSlug, $participantCategorySlug, $activeFilter),
        ]);
    }

    /**
     * @return list<array{url: string, label: string, active: bool}>
     */
    private function buildFilterTabs(?string $eventSlug, ?string $participantCategorySlug, string $active): array
    {
        $routeArgs = [
            'eventSlug'               => $eventSlug,
            'participantCategorySlug' => $participantCategorySlug,
        ];

        return [
            ['url' => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_list',            $routeArgs), 'label' => 'Vše',                'active' => self::FILTER_ALL            === $active],
            ['url' => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_unpaid',          $routeArgs), 'label' => 'Nezaplacení',        'active' => self::FILTER_UNPAID         === $active],
            ['url' => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_unpaid_deposit',  $routeArgs), 'label' => 'Nezaplacená záloha', 'active' => self::FILTER_UNPAID_DEPOSIT === $active],
            ['url' => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_overpaid',        $routeArgs), 'label' => 'Přeplacení',         'active' => self::FILTER_OVERPAID       === $active],
            ['url' => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_food',            $routeArgs), 'label' => 'Stravovací omezení', 'active' => self::FILTER_FOOD           === $active],
        ];
    }

    public function getParticipantsData(
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
        bool $includeDeleted = true
    ): array {
        $data = [];
        $data['participantCategory']
            = $this->participantCategoryService->getParticipantTypeBySlug($participantCategorySlug);
        $data['event'] = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        $data['participants'] = $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ParticipantRepository::CRITERIA_EVENT => $data['event'],
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $data['participantCategory'],
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
        ]);
        $data['title'] = "Přehled účastníků :: ADMIN";
        $participantsArray = $data['participants']->toArray();
        usort($participantsArray, static function (Participant $a, Participant $b) {
            return strcoll($a->getSortableName(), $b->getSortableName());
        });
        $data['participants'] = new ArrayCollection($participantsArray);

        return $data;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function showParticipantsCsv(
        Environment $twig,
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
        bool $includeDeleted = false,
    ): Response {
        $fileName = "participants";
        $fileName .= $eventSlug ? ('_'.$eventSlug) : '';
        $fileName .= $participantCategorySlug ? ('_'.$participantCategorySlug) : '';
        $fileName .= '_'.str_replace('T', '_', (new DateTime())->format('c'));
        $fileName .= '.csv';

        $data = $this->getParticipantsData($eventSlug, $participantCategorySlug, $includeDeleted);

        $response = new StreamedResponse(static function () use ($twig, $data): void {
            $twig->display('@OswisOrgOswisCalendar/web_admin/participants.csv.twig', $data);
            flush();
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$fileName}\"");

        return $response;
    }

    public function showPayments(): Response
    {
        return $this->render("@OswisOrgOswisCalendar/web_admin/payments.html.twig", [
            'payments' => $this->em->getRepository(ParticipantPayment::class)
                ->findBy([], ['dateTime' => 'DESC'], 500),
            'title' => "Přehled plateb účastníků :: ADMIN",
        ]);
    }

    public function showYearsCompare(?string $eventSeriesSlug = null): Response
    {
        $events = $this->eventService->getRepository()->getEvents([
            EventRepository::CRITERIA_SERIES_SLUG => $eventSeriesSlug,
            EventRepository::CRITERIA_TYPE_STRING => 'year-of-event',
        ]);

        return $this->render("@OswisOrgOswisCalendar/web_admin/years-compare.html.twig", [
            'title' => "Srovnání ročníků :: ADMIN",
            'events' => $events,
        ]);
    }


    /**
     * @throws NotFoundException
     */
    public function showEvent(?string $eventSlug = null): Response
    {
        $event = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        $defaultEvent = $this->eventService->getDefaultEvent();
        $event ??= $defaultEvent;
        if (null === $event) {
            throw new NotFoundException("Událost '$eventSlug' nenalezena.");
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/event.html.twig', [
            'title'          => 'Přehled události :: ADMIN',
            'event'          => $event,
            'isDefaultEvent' => $event === $defaultEvent,
            ...$this->eventAggregationsService->getEventAggregations($event),
        ]);
    }
}
