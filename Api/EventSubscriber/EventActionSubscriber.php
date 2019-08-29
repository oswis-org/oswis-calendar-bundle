<?php

namespace Zakjakub\OswisCalendarBundle\Api\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Zakjakub\OswisCalendarBundle\Api\Dto\EventActionRequest;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Manager\EventParticipantManager;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use Zakjakub\OswisCoreBundle\Service\PdfGenerator;
use function in_array;

/**
 * Class EventActionSubscriber
 * @package Zakjakub\OswisCalendarBundle\Api\EventSubscriber
 */
final class EventActionSubscriber implements EventSubscriberInterface
{
    public const TYPE_INFOMAIL = 'infomail';
    public const ALLOWED_ACTION_TYPES = [self::TYPE_INFOMAIL];

    /**
     * @var EventParticipantManager
     */
    private $eventParticipantManager;

    /**
     * @var PdfGenerator
     */
    private $pdfGenerator;

    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings,
        PdfGenerator $pdfGenerator
    ) {
        $this->pdfGenerator = $pdfGenerator;
        $this->eventParticipantManager = new EventParticipantManager($em, $mailer, $oswisCoreSettings, $logger);
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => ['eventAction', EventPriorities::POST_VALIDATE],
        ];
    }

    /** @noinspection PhpUnused */
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
        if (!in_array($type, self::ALLOWED_ACTION_TYPES, true)) {
            $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));

            return;
        }
        if (self::TYPE_INFOMAIL === $type) {
            $event->setResponse($this->sendInfoMailAction($eventActionRequest));

            return;
        }
        if ($output) {
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
        $eventParticipantTypeOfType = $eventActionRequest->eventParticipantTypeOfType ?? EventParticipantType::TYPE_ATTENDEE;
        if (!$event) {
            return new JsonResponse('Zasláno 0 zpráv. Událost nenalezena.', Response::HTTP_NOT_FOUND);
        }
        $successCount = $this->eventParticipantManager->sendInfoMails(
            $this->pdfGenerator,
            $event,
            $eventParticipantTypeOfType,
            $recursiveDepth,
            $count,
            'event-action-api-multiple'
        );

        return new JsonResponse("Zasláno $successCount zpráv z $count vyžádaných.", Response::HTTP_CREATED);
    }
}
