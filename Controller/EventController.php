<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Controller;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventRegistrationRange;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\EventParticipantTypeService;
use OswisOrg\OswisCalendarBundle\Service\EventService;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotFoundException;
use OswisOrg\OswisCoreBundle\Utils\DateTimeUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventController extends AbstractController
{
    public const RANGE_ALL = null;
    public const RANGE_YEAR = 'year';
    public const RANGE_MONTH = 'month';
    public const RANGE_WEEK = 'week';
    public const RANGE_DAY = 'day';

    protected EventService $eventService;

    protected EventRepository $eventRepository;

    protected EventParticipantTypeService $participantTypeService;

    protected OswisCalendarSettingsProvider $calendarSettings;

    public function __construct(EventService $eventService, EventParticipantTypeService $participantTypeService, OswisCalendarSettingsProvider $calendarSettings)
    {
        $this->eventService = $eventService;
        $this->eventRepository = $eventService->getRepository();
        $this->participantTypeService = $participantTypeService;
        $this->calendarSettings = $calendarSettings;
    }

    /**
     * @param string|null $eventSlug
     *
     * @return Response
     * @throws NotFoundHttpException
     */
    final public function showEvent(?string $eventSlug = null): Response
    {
        $defaultEventSlug = $this->calendarSettings->getDefaultEvent();
        if (empty($eventSlug) && !empty($defaultEventSlug)) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_event', ['eventSlug' => $defaultEventSlug]);
        }
        if (null === $eventSlug) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_events');
        }
        $eventRepo = $this->eventService->getRepository();
        $opts = [
            EventRepository::CRITERIA_SLUG               => $eventSlug,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
        ];
        $event = $eventRepo->getEvent($opts);
        if (!($event instanceof Event)) {
            throw new OswisNotFoundException('Událost nenalezena.');
        }
        $navEvents = new ArrayCollection();
        if (null !== $event->getSeries() && null !== $event->getType()) {
            $navEvents = $event->getSeries()
                ->getEvents(
                    $event->getType()
                        ->getType(),
                    $event->isBatch() ? $event->getStartYear() : null
                );
        }
        $data = array(
            'title'       => $event->getShortName(),
            'description' => $event->getDescription(),
            'navEvents'   => $navEvents,
            'event'       => $event,
            'organizer'   => $this->eventService->getOrganizer($event),
        );

        return $this->render('@OswisOrgOswisCalendar/web/pages/event.html.twig', $data);
    }

    /**
     * @param string|null $eventSlug
     *
     * @return Response
     * @throws NotFoundHttpException
     */
    final public function showEventLeaflet(?string $eventSlug = null): Response
    {
        $defaultEventSlug = $this->calendarSettings->getDefaultEvent();
        if (empty($eventSlug) && !empty($defaultEventSlug)) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_event_leaflet', ['eventSlug' => $defaultEventSlug]);
        }
        $eventRepo = $this->eventService->getRepository();
        $opts = [
            EventRepository::CRITERIA_SLUG               => $eventSlug,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
        ];
        $event = $eventRepo->getEvent($opts);
        if (!($event instanceof Event)) {
            throw new OswisNotFoundException('Událost nenalezena.');
        }
        $data = array(
            'title'       => $event->getShortName(),
            'description' => $event->getDescription(),
            'event'       => $event,
            'organizer'   => $this->eventService->getOrganizer($event),
        );
        $templatePath = '@OswisOrgOswisCalendar/web/pages/leaflet/'.$event->getSlug().'.html.twig';
        if ($this->get('twig')
            ->getLoader()
            ->exists($templatePath)) {
            return $this->render($templatePath, $data);
        }
        throw new OswisNotFoundException('Leták nenalezen.');
    }

    public function getMagicEvents(): Collection
    {
        $opts = [
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_ONLY_WITHOUT_DATE  => true,
            EventRepository::CRITERIA_ONLY_ROOT          => true,
        ];

        return $this->eventRepository->getEvents($opts);
    }

    /**
     * @param string|null   $range
     * @param DateTime|null $start
     * @param DateTime|null $end
     * @param int|null      $limit
     * @param int|null      $offset
     *
     * @return Response
     * @throws Exception
     */
    public function showEvents(
        ?string $range = null,
        ?DateTime $start = null,
        ?DateTime $end = null,
        ?int $limit = null,
        ?int $offset = null
    ): Response {
        $context = [
            'events'    => $this->getEvents($range, $start, $end, $limit, $offset),
            'range'     => $range,
            'navEvents' => [],
        ];

        return $this->render('@OswisOrgOswisCalendar/web/pages/events.html.twig', $context);
    }

    /**
     * @param string|null   $range
     * @param DateTime|null $start
     * @param DateTime|null $end
     * @param int|null      $limit
     * @param int|null      $offset
     * @param string|null   $eventSlug
     * @param bool|null     $onlyRoot
     *
     * @return Collection
     * @throws Exception
     */
    public function getEvents(
        ?string $range = null,
        ?DateTime $start = null,
        ?DateTime $end = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $eventSlug = null,
        ?bool $onlyRoot = true
    ): Collection {
        $range ??= self::RANGE_ALL;
        $limit = $limit < 1 ? null : $limit;
        $offset = $offset < 1 ? null : $offset;
        $start = DateTimeUtils::getDateTimeByRange($start, $range, false);
        $end = DateTimeUtils::getDateTimeByRange($end, $range, true);
        $opts = [
            EventRepository::CRITERIA_START              => $start,
            EventRepository::CRITERIA_END                => $end,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_ONLY_ROOT          => $onlyRoot,
            EventRepository::CRITERIA_SLUG               => $eventSlug,
        ];

        return $this->eventRepository->getEvents($opts, $limit, $offset);
    }

    /**
     * @param string|null $range
     * @param string|null $rangeValue
     *
     * @return Response
     * @throws Exception
     */
    public function showFutureEvents(?string $range = null, ?string $rangeValue = null): Response
    {
        $start = new DateTime($rangeValue);
        $end = new DateTime($rangeValue);
        $events = $this->getEvents($range, $start, $end);
        $context = [
            'range'     => $range,
            'start'     => $start,
            'end'       => $end,
            'events'    => $events,
            'navRanges' => [], /////////
        ];

        return $this->render('@OswisOrgOswisCalendar/web/pages/events.html.twig', $context);
    }

    /**
     * Renders page with list of registration ranges.
     *
     * If eventSlug is defined, renders page with registration ranges for this event and subEvents, if it's not defined, renders list for all events.
     *
     * @param string        $eventSlug       Slug for selected event.
     * @param string|null   $participantType Restriction by participant type.
     * @param DateTime|null $dateTime        Reference dateTime ("now" if not selected).
     *
     * @return Response Page with registration ranges.
     * @throws Exception Error occurred when getting events.
     */
    public function showRegistrationRanges(string $eventSlug = null, ?string $participantType = null, ?DateTime $dateTime = null): Response
    {
        $event = $eventSlug ? $this->getEvents(null, null, null, null, null, $eventSlug, false)[0] ?? null : null;
        if (!empty($eventSlug) && empty($event)) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_event_registrations');
        }
        $events = $event instanceof Event ? new ArrayCollection([$event, ...$event->getSubEvents()]) : $this->getEvents(null, null, null, null, null, null, false);
        $context = [
            'events' => self::getRegistrationRanges($events, $participantType, true, $dateTime),
        ];

        return $this->render('@OswisOrgOswisCalendar/web/pages/event-registration-ranges.html.twig', $context);
    }

    /**
     * Helper for getting structured array of registration ranges from given collection of events.
     *
     * @param Collection    $events          Collection of events to extract registration ranges.
     * @param string|null   $participantType Restriction to event participant type.
     * @param DateTime|null $dateTime        Reference dateTime.
     *
     * @return array [
     *     eventId => ['event' => Event, 'ranges' => Collection<EventRegistrationRange>],
     * ]
     */
    public static function getRegistrationRanges(Collection $events, ?string $participantType = null, ?bool $onlyPublicOnWeb = true, ?DateTime $dateTime = null): array
    {
        $ranges = [];
        foreach ($events as $event) {
            assert($event instanceof Event);
            $eventRanges = $event->getRegistrationRangesByTypeOfType($participantType, $dateTime);
            if ($onlyPublicOnWeb) {
                $eventRanges = $eventRanges->filter(fn(EventRegistrationRange $range) => $range->isPublicOnWeb());
            }
            $ranges[$event->getId()] ??= [
                'event'  => $event,
                'ranges' => $eventRanges,
            ];
        }

        return $ranges;
    }
}
