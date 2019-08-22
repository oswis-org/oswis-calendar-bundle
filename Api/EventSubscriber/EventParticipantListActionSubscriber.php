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
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Manager\EventParticipantManager;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use Zakjakub\OswisCoreBundle\Service\PdfGenerator;
use function assert;

final class EventParticipantListActionSubscriber implements EventSubscriberInterface
{
    public const DEFAULT_EVENT_PARTICIPANT_TYPE_SLUG = 'ucastnik';

    /**
     * @var EntityManagerInterface
     */
    protected $em;

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
        $this->em = $em;
        $this->pdfGenerator = $pdfGenerator;
        $this->eventParticipantManager = new EventParticipantManager($em, $mailer, $oswisCoreSettings, $logger);
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
        $request = $event->getControllerResult();
        $eventParticipantType = $request->eventParticipantType;
        if (!$eventParticipantType) {
            $eventParticipantTypeRepository = $this->em->getRepository(EventParticipantType::class);
            $eventParticipantType = $eventParticipantTypeRepository->findOneBy(['slug' => self::DEFAULT_EVENT_PARTICIPANT_TYPE_SLUG]);
        }
        try {
            assert($eventParticipantType instanceof EventParticipantType);
            $this->eventParticipantManager->sendEventParticipantList(
                $this->pdfGenerator,
                $request->event,
                $eventParticipantType,
                $request->detailed ?? false,
                $request->title ?? null
            );
        } catch (Exception $e) {
            $event->setResponse(new JsonResponse(['data' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR));

            return;
        }
        $event->setResponse(new JsonResponse(['data' => 'Odesláno.'], Response::HTTP_CREATED));
    }
}
