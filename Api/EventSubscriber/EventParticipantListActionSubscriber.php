<?php
/**
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Api\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use OswisOrg\OswisCalendarBundle\Service\EventParticipantService;
use OswisOrg\OswisCoreBundle\Service\ExportService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function assert;

final class EventParticipantListActionSubscriber implements EventSubscriberInterface
{
    public const DEFAULT_EVENT_PARTICIPANT_TYPE = 'ucastnik';

    // TODO: ParticipantType slug change!
    protected EntityManagerInterface $em;

    protected ExportService $exportService;

    private EventParticipantService $participantService;

    public function __construct(ExportService $pdfGenerator, EntityManagerInterface $em, EventParticipantService $participantService)
    {
        $this->em = $em;
        $this->exportService = $pdfGenerator;
        $this->participantService = $participantService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => ['sendEventParticipantList', EventPriorities::POST_WRITE],
        ];
    }

    public function sendEventParticipantList(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if ('api_event_participant_list_action_requests_post_collection' !== $request->attributes->get('_route')) {
            return;
        }
        $request = $event->getControllerResult();
        $participantType = $request->eventParticipantType;
        if (!$participantType) {
            $participantType = $this->em->getRepository(EventParticipantType::class)->findOneBy(['slug' => self::DEFAULT_EVENT_PARTICIPANT_TYPE]);
        }
        try {
            assert($participantType instanceof EventParticipantType);
            $this->participantService->sendEventParticipantList($request->event, $participantType, $request->detailed ?? false, $request->title ?? null);
        } catch (Exception $e) {
            $event->setResponse(new JsonResponse(['data' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR));

            return;
        }
        $event->setResponse(new JsonResponse(['data' => 'Odesláno.'], Response::HTTP_CREATED));
    }
}
