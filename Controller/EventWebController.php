<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Controller;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Exception;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Repository\EventRepository;
use Zakjakub\OswisCalendarBundle\Service\EventService;
use Zakjakub\OswisCoreBundle\Exceptions\OswisNotFoundException;
use Zakjakub\OswisCoreBundle\Utils\DateTimeUtils;

class EventWebController extends AbstractController
{
    public const RANGE_ALL = null;
    public const RANGE_YEAR = 'year';
    public const RANGE_MONTH = 'month';
    public const RANGE_WEEK = 'week';
    public const RANGE_DAY = 'day';

    protected EventService $eventService;

    protected EventRepository $eventRepository;

    public function __construct(EventService $eventService)
    {
        $this->eventService = $eventService;
        $this->eventRepository = $eventService->getRepository();
    }

    /**
     * @param string|null $slug
     *
     * @return Response
     * @throws LogicException
     * @throws NotFoundHttpException
     */
    final public function eventAction(?string $slug = null): Response
    {
        if (null !== $slug) {
            $this->redirectToRoute('zakjakub_oswis_calendar_web_events');
        }
        $eventRepo = $this->eventService->getRepository();
        $opts = [
            EventRepository::CRITERIA_SLUG                     => $slug,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB_ROUTE => true,
            EventRepository::CRITERIA_INCLUDE_DELETED          => false,
        ];
        $event = $eventRepo->getEvents($opts, 1);
        if (!($event instanceof Event)) {
            throw new OswisNotFoundException('Událost nenalezena.');
        }
        $navEvents = new ArrayCollection();
        if ($event->getEventSeries() && $event->getEventType()) {
            $navEvents = $event->getEventSeries()->getEvents($event->getEventType(), $event->isBatch() ? $event->getStartYear() : null);
        }
        $data = array(
            'navEvents' => $navEvents,
            'event'     => $event,
        );

        return $this->render('@ZakjakubOswisCalendar/web/pages/event.html.twig', $data);
    }

    /**
     * @param DateTimeUtils $dateTimeUtils
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
    final public function eventsAction(
        DateTimeUtils $dateTimeUtils,
        ?string $range = self::RANGE_ALL,
        ?DateTime $start = null,
        ?DateTime $end = null,
        ?int $limit = null,
        ?int $offset = null
    ): Response {
        $limit = $limit < 1 ? null : $limit;
        $offset = $offset < 1 ? null : $offset;
        $start = $dateTimeUtils->getDateTimeByRange($start, $range, false);
        $end = $dateTimeUtils->getDateTimeByRange($end, $range, false);
        $opts = [
            EventRepository::CRITERIA_START              => $start,
            EventRepository::CRITERIA_END                => $end,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
        ];
        $events = $this->eventRepository->getEvents($opts, $limit, $offset);
        $opts = [
            EventRepository::CRITERIA_START              => $start,
            EventRepository::CRITERIA_END                => $end,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_ONLY_WITHOUT_DATE  => true,
        ];
        $withoutDateEvents = $this->eventRepository->getEvents($opts);
        $context = [
            'events'            => $events,
            'withoutDateEvents' => $withoutDateEvents,
        ];

        return $this->render('@ZakjakubOswisCalendar/web/pages/event.html.twig', $context);
    }
}
