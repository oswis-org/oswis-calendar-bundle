<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Controller;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Repository\EventRepository;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantTypeService;
use Zakjakub\OswisCalendarBundle\Service\EventService;
use Zakjakub\OswisCoreBundle\Exceptions\OswisNotFoundException;
use Zakjakub\OswisCoreBundle\Utils\DateTimeUtils;

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

    public function __construct(EventService $eventService, EventParticipantTypeService $participantTypeService)
    {
        $this->eventService = $eventService;
        $this->eventRepository = $eventService->getRepository();
        $this->participantTypeService = $participantTypeService;
    }

    /**
     * @param string|null $eventSlug
     *
     * @return Response
     * @throws LogicException
     * @throws NotFoundHttpException
     */
    final public function showEvent(?string $eventSlug = null): Response
    {
        if (null !== $eventSlug) {
            $this->redirectToRoute('zakjakub_oswis_calendar_web_events');
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
            $navEvents = $event->getSeries()->getEvents(
                $event->getType()->getType(),
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

        return $this->render('@ZakjakubOswisCalendar/web/pages/event.html.twig', $data);
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
     * @throws LogicException
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

        return $this->render('@ZakjakubOswisCalendar/web/pages/events.html.twig', $context);
    }

    /**
     * @param string|null   $range
     * @param DateTime|null $start
     * @param DateTime|null $end
     * @param int|null      $limit
     * @param int|null      $offset
     *
     * @return Collection
     * @throws Exception
     */
    public function getEvents(
        ?string $range = null,
        ?DateTime $start = null,
        ?DateTime $end = null,
        ?int $limit = null,
        ?int $offset = null
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
            EventRepository::CRITERIA_ONLY_ROOT          => true,
        ];

        return $this->eventRepository->getEvents($opts, $limit, $offset);
    }

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

        return $this->render('@ZakjakubOswisCalendar/web/pages/events.html.twig', $context);
    }
}
