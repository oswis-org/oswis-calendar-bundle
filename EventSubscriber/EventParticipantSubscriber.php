<?php

namespace Zakjakub\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Exceptions\OswisEventParticipantNotFoundException;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantService;
use function assert;
use function in_array;

final class EventParticipantSubscriber implements EventSubscriberInterface
{
    private EntityManagerInterface $em;

    private UserPasswordEncoderInterface $encoder;

    private EventParticipantService $participantService;

    public function __construct(EntityManagerInterface $em, UserPasswordEncoderInterface $encoder, EventParticipantService $participantService)
    {
        $this->em = $em;
        $this->encoder = $encoder;
        $this->participantService = $participantService;
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
     * @noinspection PhpUnused
     */
    public function postWrite(ViewEvent $event): void
    {
        $eventParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$eventParticipant instanceof EventParticipant || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }
        $eventParticipant = $this->em->getRepository(EventParticipant::class)->findOneBy(['id' => $eventParticipant->getId()]);
        assert($eventParticipant instanceof EventParticipant);
        if ($eventParticipant) {
            $this->participantService->sendMail($eventParticipant, $this->encoder, Request::METHOD_POST === $method);
        } else {
            throw new OswisEventParticipantNotFoundException();
        }
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function postValidate(ViewEvent $event): void
    {
        $newEventParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$newEventParticipant instanceof EventParticipant || $method !== Request::METHOD_PUT) {
            return;
        }
        $eventParticipant = $this->getExistingEventParticipant($newEventParticipant);
        if ($eventParticipant) {
            $newEventParticipant->setEMailConfirmationDateTime(null);
            $eventParticipant->setEMailConfirmationDateTime(null);
        } else {
            throw new OswisEventParticipantNotFoundException();
        }
    }

    private function getExistingEventParticipant(EventParticipant $newEventParticipant): ?EventParticipant
    {
        $eventParticipant = $this->em->getRepository(EventParticipant::class)->findOneBy(['id' => $newEventParticipant->getId()]);
        assert($eventParticipant instanceof EventParticipant);

        return $eventParticipant;
    }
}
