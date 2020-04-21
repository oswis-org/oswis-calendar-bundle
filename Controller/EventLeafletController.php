<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\EventParticipantTypeService;
use OswisOrg\OswisCalendarBundle\Service\EventService;

class EventLeafletController extends AbstractController
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
        return $this->render('@OswisOrgOswisCalendar/other/leaflet/leaflet.html.twig', ['slug' => $slug]);
    }
}
