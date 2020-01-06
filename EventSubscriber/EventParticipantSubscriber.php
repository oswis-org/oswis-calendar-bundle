<?php
/**
 * @noinspection PhpUnused
 * @noinspection RedundantDocCommentTagInspection
 */

namespace Zakjakub\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Exception\OswisEventParticipantNotFoundException;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantService;
use Zakjakub\OswisCalendarBundle\Service\EventService;
use function in_array;

final class EventParticipantSubscriber implements EventSubscriberInterface
{
    private EventParticipantService $participantService;

    private EventService $eventService;

    public function __construct(EventParticipantService $participantService, EventService $eventService)
    {
        $this->participantService = $participantService;
        $this->eventService = $eventService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => [
                ['postWrite', EventPriorities::POST_WRITE],
                ['postValidate', EventPriorities::POST_VALIDATE],
            ],
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function postWrite(ViewEvent $event): void
    {
        $newParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!($newParticipant instanceof EventParticipant) || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }
        $oldParticipant = $this->participantService->getRepository()->getEventParticipant(['id' => $newParticipant->getId()]);
        $this->eventService->simulateAddEventParticipant($newParticipant, $oldParticipant);
        $this->participantService->sendMail($newParticipant, Request::METHOD_POST === $method);
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function postValidate(ViewEvent $event): void
    {
        $newParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!($newParticipant instanceof EventParticipant) || $method !== Request::METHOD_PUT) {
            return;
        }
        $oldParticipant = $this->getExistingEventParticipant($newParticipant);
        if (null === $oldParticipant) {
            throw new OswisEventParticipantNotFoundException();
        }
        $newParticipant->setEMailConfirmationDateTime(null);
        $oldParticipant->setEMailConfirmationDateTime(null);
    }

    private function getExistingEventParticipant(EventParticipant $newEventParticipant): ?EventParticipant
    {
        return $this->participantService->getRepository()->getEventParticipant(['id' => $newEventParticipant->getId()]);
    }
}
