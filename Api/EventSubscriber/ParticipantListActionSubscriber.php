<?php
/**
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Api\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use OswisOrg\OswisCoreBundle\Service\ExportService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function assert;

final class ParticipantListActionSubscriber implements EventSubscriberInterface
{
    public const DEFAULT_EVENT_PARTICIPANT_TYPE = 'ucastnik';

    // TODO: ParticipantCategory slug change!
    protected EntityManagerInterface $em;

    protected ExportService $exportService;

    private ParticipantService $participantService;

    public function __construct(ExportService $pdfGenerator, EntityManagerInterface $em, ParticipantService $participantService)
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
        if ('api_participant_list_action_requests_post_collection' !== $request->attributes->get('_route')) {
            return;
        }
        $request = $event->getControllerResult();
        $participantType = $request->eventParticipantType;
        if (!$participantType) {
            $participantType = $this->em->getRepository(ParticipantCategory::class)->findOneBy(['slug' => self::DEFAULT_EVENT_PARTICIPANT_TYPE]);
        }
        try {
            assert($participantType instanceof ParticipantCategory);
            // $this->participantService->sendParticipantList($request->event, $participantType, $request->detailed ?? false, $request->title ?? null);
        } catch (Exception $e) {
            $event->setResponse(new JsonResponse(['data' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR));

            return;
        }
        $event->setResponse(new JsonResponse(['data' => 'NEOdesláno. NEImplementováno.'], Response::HTTP_CREATED));
    }
}
