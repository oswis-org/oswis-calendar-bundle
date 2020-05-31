<?php
/**
 * @noinspection PhpUnused
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\Participant;
use OswisOrg\OswisCalendarBundle\Exception\OswisParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\RegistrationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function in_array;

final class ParticipantSubscriber implements EventSubscriberInterface
{
    private RegistrationService $registrationService;

    public function __construct(RegistrationService $registrationService)
    {
        $this->registrationService = $registrationService;
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
        if (!($newParticipant instanceof Participant) || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }
        $oldParticipant = $this->getParticipantRepository()->getParticipant(['id' => $newParticipant->getId()]);
        $this->registrationService->simulateRegistration($newParticipant, $oldParticipant);
        $this->getParticipantService()->sendMail($newParticipant, Request::METHOD_POST === $method);
    }

    public function getParticipantRepository(): ParticipantRepository
    {
        return $this->getParticipantService()->getRepository();
    }

    public function getParticipantService(): ParticipantService
    {
        return $this->registrationService->getParticipantService();
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
        if (!($newParticipant instanceof Participant) || $method !== Request::METHOD_PUT) {
            return;
        }
        $oldParticipant = $this->getExistingParticipant($newParticipant);
        if (null === $oldParticipant) {
            throw new OswisParticipantNotFoundException();
        }
        $newParticipant->setEMailConfirmationDateTime(null);
        $oldParticipant->setEMailConfirmationDateTime(null);
    }

    private function getExistingParticipant(Participant $newParticipant): ?Participant
    {
        return $this->getParticipantRepository()->getParticipant(['id' => $newParticipant->getId()]);
    }
}
