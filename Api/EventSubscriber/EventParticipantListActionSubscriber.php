<?php

namespace Zakjakub\OswisCalendarBundle\Api\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantService;
use Zakjakub\OswisCoreBundle\Service\PdfGenerator;
use function assert;

final class EventParticipantListActionSubscriber implements EventSubscriberInterface
{
    public const DEFAULT_EVENT_PARTICIPANT_TYPE_SLUG = 'ucastnik';

    protected EntityManagerInterface $em;

    protected PdfGenerator $pdfGenerator;

    private EventParticipantService $participantService;

    public function __construct(PdfGenerator $pdfGenerator, EntityManagerInterface $em, EventParticipantService $participantService)
    {
        $this->em = $em;
        $this->pdfGenerator = $pdfGenerator;
        $this->participantService = $participantService;
    }

    /**
     * @return array<string, array<int|string>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => ['sendEventParticipantList', EventPriorities::POST_WRITE],
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @noinspection PhpUnused
     */
    public function sendEventParticipantList(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if ('api_event_participant_list_action_requests_post_collection' !== $request->attributes->get('_route')) {
            return;
        }
        $request = $event->getControllerResult();
        $participantType = $request->eventParticipantType;
        if (!$participantType) {
            $participantType = $this->em->getRepository(EventParticipantType::class)->findOneBy(['slug' => self::DEFAULT_EVENT_PARTICIPANT_TYPE_SLUG]);
        }
        try {
            assert($participantType instanceof EventParticipantType);
            $this->participantService->sendEventParticipantList($this->pdfGenerator, $request->event, $participantType, $request->detailed ?? false, $request->title ?? null);
        } catch (Exception $e) {
            $event->setResponse(new JsonResponse(['data' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR));

            return;
        }
        $event->setResponse(new JsonResponse(['data' => 'Odesláno.'], Response::HTTP_CREATED));
    }
}
