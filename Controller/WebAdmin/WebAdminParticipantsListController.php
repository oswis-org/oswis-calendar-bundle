<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
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

class WebAdminParticipantsListController extends AbstractController
{
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

    public function showParticipants(
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
        bool $includeDeleted = true,
    ): Response {
        return $this->render(
            "@OswisOrgOswisCalendar/web_admin/participants.html.twig",
            $this->getParticipantsData($eventSlug, $participantCategorySlug, $includeDeleted),
        );
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
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
        bool $includeDeleted = false,
    ): Response {
        $fileName = "participants";
        $fileName .= $eventSlug ? ('_'.$eventSlug) : '';
        $fileName .= $participantCategorySlug ? ('_'.$participantCategorySlug) : '';
        $fileName .= '_'.str_replace('T', '_', (new DateTime())->format('c'));
        $fileName .= '.csv';

        return $this->render(
            "@OswisOrgOswisCalendar/web_admin/participants.csv.twig",
            $this->getParticipantsData($eventSlug, $participantCategorySlug, $includeDeleted),
            new Response(headers: [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            ]),
        );
    }

    public function showPayments(): Response
    {
        return $this->render("@OswisOrgOswisCalendar/web_admin/payments.html.twig", [
            'payments' => $this->em->getRepository(ParticipantPayment::class)->findAll(),
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
