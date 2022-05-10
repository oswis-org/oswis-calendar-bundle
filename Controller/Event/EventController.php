<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\Event;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationOfferService;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class EventController extends AbstractController
{
    public const PAGINATION = 10;

    public const RANGE_ALL = null;
    public const RANGE_YEAR = 'year';
    public const RANGE_MONTH = 'month';
    public const RANGE_WEEK = 'week';
    public const RANGE_DAY = 'day';

    public function __construct(
        protected EventService $eventService,
        protected ParticipantService $participantService,
        protected RegistrationOfferService $regRangeService,
    ) {
    }

    /**
     * @param  string|null  $eventSlug
     *
     * @return Response
     * @throws NotFoundException
     */
    final public function showEvent(?string $eventSlug = null): Response
    {
        $defaultEvent = empty($eventSlug) ? $this->eventService->getDefaultEvent() : null;
        if (null !== $defaultEvent) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_event', ['eventSlug' => $defaultEvent->getSlug()]);
        }
        if (null === $eventSlug) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_events');
        }
        if (!(($event = $this->eventService->getRepository()->getEvent($this->getWebPublicEventOpts($eventSlug))) instanceof Event)) {
            throw new NotFoundException('Událost nenalezena.');
        }

        return $this->render('@OswisOrgOswisCalendar/web/pages/event.html.twig', [
            'title'       => $event->getShortName(),
            'description' => $event->getDescription(),
            'ranges'      => $this->regRangeService->getEventRegistrationRanges(new ArrayCollection([$event, ...$event->getSubEvents()])),
            'event'       => $event,
            'organizer'   => $this->participantService->getOrganizer($event),
        ]);
    }

    public function getWebPublicEventOpts(?string $eventSlug = null): array
    {
        return [
            EventRepository::CRITERIA_SLUG               => $eventSlug,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
        ];
    }

    public function showEventsNavigationChunk(?string $eventSlug = null): Response
    {
        $eventRepo = $this->eventService->getRepository();
        $event = $eventRepo->getEvent($this->getWebPublicEventOpts($eventSlug));

        return $this->render('@OswisOrgOswisCalendar/web/parts/event-nav.html.twig', [
            'event'     => $event,
            'navEvents' => $this->getNavigationEvents($event),
        ]);
    }

    public function getNavigationEvents(?Event $event = null): Collection
    {
        if (null === $event || null === ($series = $event->getGroup()) || null === ($typeString = $event->getType())) {
            return new ArrayCollection();
        }

        return $series->getEvents(
            ''.$typeString,
            $event->isBatch() ? $event->getStartYear() : null
        );
    }

    /**
     * @param  \Twig\Environment  $twig
     * @param  string|null  $eventSlug
     *
     * @return Response
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\NotFoundException
     */
    final public function showEventLeaflet(Environment $twig, ?string $eventSlug = null): Response
    {
        $defaultEvent = empty($eventSlug) ? $this->eventService->getDefaultEvent() : null;
        if (null !== $defaultEvent) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_event_leaflet', ['eventSlug' => $defaultEvent->getSlug()]);
        }
        $eventRepo = $this->eventService->getRepository();
        $opts = [
            EventRepository::CRITERIA_SLUG               => $eventSlug,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
        ];
        $event = $eventRepo->getEvent($opts);
        if (!($event instanceof Event)) {
            throw new NotFoundException('Událost nenalezena.');
        }
        $data = [
            'title'       => $event->getShortName(),
            'description' => $event->getDescription(),
            'event'       => $event,
            'organizer'   => $this->participantService->getOrganizer($event),
        ];
        $templatePath = '@OswisOrgOswisCalendar/web/pages/leaflet/'.$event->getSlug().'.html.twig';
        if ($twig->getLoader()->exists($templatePath)) {
            return $this->render($templatePath, $data);
        }
        throw new NotFoundException('Leták nenalezen.');
    }

    public function getMagicEvents(): Collection
    {
        $opts = [
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_ONLY_WITHOUT_DATE  => true,
            EventRepository::CRITERIA_ONLY_ROOT          => true,
        ];

        return $this->getEventRepository()->getEvents($opts);
    }

    public function getEventRepository(): EventRepository
    {
        return $this->eventService->getRepository();
    }

    /**
     * @return Response
     * @throws Exception
     */
    public function showPastEvents(): Response
    {
        return $this->showEvents(null, null, new DateTime());
    }

    /**
     * @param  string|null  $range
     * @param  DateTime|null  $start
     * @param  DateTime|null  $end
     * @param  int|null  $limit
     * @param  int|null  $offset
     *
     * @return Response
     * @throws Exception
     */
    public function showEvents(?string $range = null, ?DateTime $start = null, ?DateTime $end = null, ?int $limit = null, ?int $offset = null): Response
    {
        $context = [
            'events'    => $this->eventService->getEvents($range, $start, $end, $limit, $offset),
            'range'     => $range,
            'navEvents' => [],
        ];

        return $this->render('@OswisOrgOswisCalendar/web/pages/events.html.twig', $context);
    }

    /**
     * @param  int|null  $page
     *
     * @return Response
     * @throws Exception
     */
    public function showFutureEvents(?int $page = 0): Response
    {
        $pageSize = self::PAGINATION;
        $page ??= 0;

        return $this->render(
            '@OswisOrgOswisCalendar/web/pages/events.html.twig',
            ['events' => $this->eventService->getEvents(null, new DateTime(), null, $pageSize, $page * $pageSize),]
        );
    }

    public function showCurrentEvent(?string $prefix = null, ?string $suffix = null): Response
    {
        return $this->render('@OswisOrgOswisCalendar/web/parts/event-info-banner.html.twig', [
            'event'  => $this->eventService->getDefaultEvent(),
            'prefix' => $prefix,
            'suffix' => $suffix,
        ]);
    }
}
