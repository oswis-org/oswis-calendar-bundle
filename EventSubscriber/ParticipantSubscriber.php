<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Symfony\EventListener\EventPriorities;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantMailService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationFlagOfferService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationOfferService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function in_array;

final class ParticipantSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ParticipantMailService $participantMailService,
        private readonly LoggerInterface $logger,
        protected readonly RegistrationFlagOfferService $flagRangeService,
        protected readonly RegistrationOfferService     $registrationOfferService,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => [
                ['postValidate', EventPriorities::POST_VALIDATE],
                ['postWrite', EventPriorities::POST_WRITE],
            ],
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function postValidate(ViewEvent $event): void
    {
        $participant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!($participant instanceof Participant)
            || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }
        $this->logger->notice("ParticipantSubscriber->postValidate()");
        // TODO
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function postWrite(ViewEvent $event): void
    {
        $participant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!($participant instanceof Participant)
            || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }
        $this->logger->notice("ParticipantSubscriber->postWrite()");
        $this->participantMailService->sendSummary($participant);
        $this->flagRangeService->updateUsages($participant);
        $regRange = $participant->getOffer();
        if ($regRange) {
            $this->registrationOfferService->updateUsage($regRange);
        }
    }
}
