<?php

namespace Zakjakub\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantPaymentService;
use Zakjakub\OswisCoreBundle\Exceptions\OswisException;

final class EventParticipantPaymentSubscriber implements EventSubscriberInterface
{
    private EventParticipantPaymentService $paymentService;

    public function __construct(EventParticipantPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
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
        $eventParticipantPayment = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$eventParticipantPayment instanceof EventParticipantPayment || Request::METHOD_POST !== $method) {
            return;
        }
        $this->paymentService->sendConfirmation($eventParticipantPayment);
    }

    /**
     * @param ViewEvent $event
     *
     * @throws OswisException
     * @throws SuspiciousOperationException
     * @noinspection PhpUnused
     */
    public function postValidate(ViewEvent $event): void
    {
        $eventParticipantPayment = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$eventParticipantPayment instanceof EventParticipantPayment) {
            return;
        }
        if (Request::METHOD_PUT === $method) {
            throw new OswisException('Změna platby není povolena.');
        }
    }
}
