<?php
/**
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Controller;

use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\EventParticipantTypeService;
use OswisOrg\OswisCalendarBundle\Service\EventService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class EventRegistrationRangeController extends AbstractController
{
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

}
