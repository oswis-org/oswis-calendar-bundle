<?php

namespace OswisOrg\OswisCalendarBundle\Api\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function in_array;

final class EventActionSubscriber implements EventSubscriberInterface
{
    public const ALLOWED_ACTION_TYPES = [];

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
        if (empty(self::ALLOWED_ACTION_TYPES) || !in_array((string)$type, self::ALLOWED_ACTION_TYPES, true)) {
            $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));

            return;
        }
        if (!empty($output)) {
            $data = ['data' => chunk_split(base64_encode($output))];
            $event->setResponse(new JsonResponse($data, Response::HTTP_CREATED));

            return;
        }
        $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));
    }
}
