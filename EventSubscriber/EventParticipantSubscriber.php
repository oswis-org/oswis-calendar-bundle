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
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Exceptions\OswisEventParticipantNotFoundException;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantService;
use Zakjakub\OswisCalendarBundle\Service\EventService;
use function in_array;

final class EventParticipantSubscriber implements EventSubscriberInterface
{
    private UserPasswordEncoderInterface $encoder;

    private EventParticipantService $participantService;

    private EventService $eventService;

    public function __construct(
        UserPasswordEncoderInterface $encoder,
        EventParticipantService $participantService,
        EventService $eventService
    ) {
        $this->encoder = $encoder;
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
        $newEventParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$newEventParticipant instanceof EventParticipant || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }
        $oldEventParticipant = $this->participantService->getRepository()->findOneBy(['id' => $newEventParticipant->getId()]);
        if (null === $newEventParticipant) {
            throw new OswisEventParticipantNotFoundException();
        }
        $this->eventService->simulateAddEventParticipant($newEventParticipant, $oldEventParticipant);
        $this->participantService->sendMail($newEventParticipant, $this->encoder, Request::METHOD_POST === $method);
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function postValidate(ViewEvent $event): void
    {
        $newEventParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!($newEventParticipant instanceof EventParticipant) || $method !== Request::METHOD_PUT) {
            return;
        }
        $oldEventParticipant = $this->getExistingEventParticipant($newEventParticipant);
        if (null === $oldEventParticipant) {
            throw new OswisEventParticipantNotFoundException();
        }
        $newEventParticipant->setEMailConfirmationDateTime(null);
        $oldEventParticipant->setEMailConfirmationDateTime(null);
    }

    private function getExistingEventParticipant(EventParticipant $newEventParticipant): ?EventParticipant
    {
        return $this->participantService->getRepository()->findOneBy(['id' => $newEventParticipant->getId()]);
    }
}
