<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantPaymentService;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ParticipantPaymentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ParticipantPaymentService $paymentService,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => [
                ['postValidate', EventPriorities::POST_VALIDATE],
            ],
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @throws OswisException
     * @throws SuspiciousOperationException
     */
    public function postValidate(ViewEvent $event): void
    {
        $participantPayment = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$participantPayment instanceof ParticipantPayment) {
            return;
        }
        if (Request::METHOD_PUT === $method) {
            throw new OswisException('Změna platby není povolena.');
        }
        $this->paymentService->create($participantPayment);
    }
}
