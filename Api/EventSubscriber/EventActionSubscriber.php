<?php

namespace OswisOrg\OswisCalendarBundle\Api\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Exception;
use OswisOrg\OswisCalendarBundle\Api\Dto\EventActionRequest;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function in_array;

final class EventActionSubscriber implements EventSubscriberInterface
{
    public const TYPE_INFOMAIL = 'infomail';
    public const TYPE_FEEDBACK = 'feedback';
    public const ALLOWED_ACTION_TYPES = [self::TYPE_INFOMAIL, self::TYPE_FEEDBACK];

    private ParticipantService $participantService;

    public function __construct(ParticipantService $participantService)
    {
        $this->participantService = $participantService;
    }

    /**
     * @return  array<string, array<int, int|string>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => ['eventAction', EventPriorities::POST_VALIDATE],
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function eventAction(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if ('api_event_action_requests_post_collection' !== $request->attributes->get('_route')) {
            return;
        }
        $output = null;
        $eventActionRequest = $event->getControllerResult();
        $type = $eventActionRequest->type;
        if (!in_array($type, self::ALLOWED_ACTION_TYPES, true)) {
            $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));

            return;
        }
        if (self::TYPE_INFOMAIL === $type) {
            $event->setResponse($this->sendInfoMailAction($eventActionRequest));

            return;
        }
        if (self::TYPE_FEEDBACK === $type) {
            $event->setResponse($this->sendFeedbackMailAction($eventActionRequest));

            return;
        }
        if (!empty($output)) {
            $data = ['data' => chunk_split(base64_encode($output))];
            $event->setResponse(new JsonResponse($data, Response::HTTP_CREATED));

            return;
        }
        $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));
    }

    public function sendInfoMailAction(EventActionRequest $eventActionRequest): Response
    {
        $event = $eventActionRequest->event ?? null;
        $count = $eventActionRequest->count ?? 0;
        $recursiveDepth = $eventActionRequest->recursiveDepth ?? 0;
        $participantTypeOfType = $eventActionRequest->participantTypeOfType ?? ParticipantType::TYPE_ATTENDEE;
        if (!$event) {
            return new JsonResponse('Zasláno 0 zpráv. Událost nenalezena.', Response::HTTP_NOT_FOUND);
        }
        $successCount = $this->participantService->sendInfoMails(
            $event,
            $participantTypeOfType,
            $recursiveDepth,
            $count,
            'event-action-api-multiple'
        );

        return new JsonResponse("Zasláno $successCount zpráv z $count vyžádaných.", Response::HTTP_CREATED);
    }

    public function sendFeedbackMailAction(EventActionRequest $eventActionRequest): Response
    {
        $event = $eventActionRequest->event ?? null;
        $count = $eventActionRequest->count ?? 0;
        $startId = $eventActionRequest->startId ?? 0;
        $endId = $eventActionRequest->endId ?? 0;
        $recursiveDepth = $eventActionRequest->recursiveDepth ?? 0;
        $participantTypeOfType = $eventActionRequest->participantTypeOfType ?? ParticipantType::TYPE_ATTENDEE;
        if (!$event) {
            return new JsonResponse('Zasláno 0 zpráv. Událost nenalezena.', Response::HTTP_NOT_FOUND);
        }
        $successCount = $this->participantService->sendFeedBackMails($event, $participantTypeOfType, $recursiveDepth, $startId, $endId);

        return new JsonResponse("Zasláno $successCount zpráv z $count vyžádaných.", Response::HTTP_CREATED);
    }
}
