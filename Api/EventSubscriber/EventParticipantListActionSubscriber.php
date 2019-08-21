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
use Twig\Environment;
use Zakjakub\OswisCalendarBundle\Manager\EventParticipantManager;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use Zakjakub\OswisCoreBundle\Service\PdfGenerator;

final class EventParticipantListActionSubscriber implements EventSubscriberInterface
{
    /**
     * @var PdfGenerator
     */
    protected $pdfGenerator;

    /**
     * @var EventParticipantManager
     */
    private $eventParticipantManager;

    public function __construct(
        PdfGenerator $pdfGenerator,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings,
        Environment $templating
    ) {
        $this->eventParticipantManager = new EventParticipantManager($em, $mailer, $oswisCoreSettings, $logger, $templating);
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => ['sendEventParticipantList', EventPriorities::POST_WRITE],
        ];
    }

    /** @noinspection PhpUnused */
    /**
     * @param ViewEvent $event
     */
    public function sendEventParticipantList(ViewEvent $event): void
    {
        $request = $event->getRequest();

        if ('api_event_participant_list_action_requests_post_collection' !== $request->attributes->get('_route')) {
            return;
        }

        $reservationListActionRequest = $event->getControllerResult();

        try {
            $this->eventParticipantManager->sendEventParticipantList(
                $this->pdfGenerator,
                $reservationListActionRequest->event,
                $reservationListActionRequest->eventParticipantType ?? null,
                $reservationListActionRequest->detailed ?? false,
                $reservationListActionRequest->title ?? null
            );
        } catch (Exception $e) {
            $event->setResponse(new JsonResponse(['data' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR));

            return;
        }

        $event->setResponse(new JsonResponse(['data' => 'Odesláno.'], Response::HTTP_CREATED));
    }
}
