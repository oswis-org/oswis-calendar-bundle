<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Zakjakub\OswisCalendarBundle\Repository\EventRepository;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantTypeService;
use Zakjakub\OswisCalendarBundle\Service\EventService;

class EventLeafletWebController extends AbstractController
{
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
     * @param string|null $slug
     *
     * @return Response
     * @throws LogicException
     */
    final public function eventLeafletPdf(?string $slug = null): Response
    {
        return $this->render('@ZakjakubOswisCalendar/other/leaflet/leaflet.html.twig', ['slug' => $slug]);
    }
}
