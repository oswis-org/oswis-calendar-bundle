<?php
/**
 * @noinspection PhpUnused
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Exception\ParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function in_array;

final class ParticipantSubscriber implements EventSubscriberInterface
{
    private ParticipantService $participantService;

    public function __construct(ParticipantService $participantService)
    {
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
     */
    public function postWrite(ViewEvent $event): void
    {
        $newParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!($newParticipant instanceof Participant) || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }
        // TODO:
        // $this->getParticipantService()->sendMail($newParticipant, Request::METHOD_POST === $method);
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
            throw new ParticipantNotFoundException();
        }
        // TODO
    }

    private function getExistingParticipant(Participant $newParticipant): ?Participant
    {
        return $this->getParticipantRepository()->getParticipant(['id' => $newParticipant->getId()]);
    }

    public function getParticipantRepository(): ParticipantRepository
    {
        return $this->getParticipantService()->getRepository();
    }

    public function getParticipantService(): ParticipantService
    {
        return $this->participantService;
    }
}
