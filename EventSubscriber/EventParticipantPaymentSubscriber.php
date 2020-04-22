<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment;
use OswisOrg\OswisCalendarBundle\Service\EventParticipantPaymentService;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

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
