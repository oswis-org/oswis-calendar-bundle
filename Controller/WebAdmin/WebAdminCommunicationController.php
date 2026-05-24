<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Service\Communication\CommunicationTimelineService;
use OswisOrg\OswisCoreBundle\Interfaces\Communication\CommunicationEntryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Participant communication timeline — Twig admin view + JSON REST endpoint.
 *
 * Spec: docs/superpowers/specs/2026-05-24-communication-history-design.md §5 A.1 + §6.
 *
 * SECURITY: Both endpoints return the FULL timeline including admin-internal
 * notes (isPublicForParticipant=false). Class-level ROLE_ADMIN is REQUIRED —
 * the bundle JWT firewall on /api/v1 alone is not enough since any logged-in
 * participant has a valid JWT.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminCommunicationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommunicationTimelineService $timelineService,
    ) {
    }

    public function timelinePage(int $participantId): Response
    {
        $participant = $this->em->find(Participant::class, $participantId)
            ?? throw $this->createNotFoundException();

        $entries = $this->timelineService->forParticipant($participant, includeInternal: true);

        return $this->render('@OswisOrgOswisCalendar/web_admin/communication/timeline.html.twig', [
            'page_title'  => sprintf('Komunikace s účastníkem #%d :: ADMIN', $participantId),
            'pageTitle'   => sprintf('Komunikace s účastníkem #%d', $participantId),
            'participant' => $participant,
            'entries'     => $entries,
            'isAdmin'     => true,
        ]);
    }

    public function timelineJson(int $participantId): JsonResponse
    {
        $participant = $this->em->find(Participant::class, $participantId)
            ?? throw $this->createNotFoundException();

        $entries = $this->timelineService->forParticipant($participant, includeInternal: true);

        return $this->json([
            'participantId' => $participantId,
            'entries'       => array_map(fn (CommunicationEntryInterface $entry): array => [
                'id'                     => $entry->getId(),
                'channel'                => $entry->getChannel()->value,
                'channelLabel'           => $entry->getChannel()->label(),
                'channelIcon'            => $entry->getChannel()->iconifyName(),
                'direction'              => $entry->getDirection()->value,
                'directionLabel'         => $entry->getDirection()->label(),
                'occurredAt'             => $entry->getOccurredAt()?->format(DATE_ATOM),
                'subject'                => $entry->getSubject(),
                'summary'                => $entry->getSummary(),
                'body'                   => $entry->getBody(),
                'bodyHtml'               => $entry->getBodyHtml(),
                'isPublicForParticipant' => $entry->isPublicForParticipant(),
                'messageId'              => $entry->getMessageId(),
                'inReplyTo'              => $entry->getInReplyTo(),
                'threadKey'              => $entry->getThreadKey(),
                'authorAppUserId'        => $entry->getAuthorAppUser()?->getId(),
            ], $entries),
        ]);
    }
}
