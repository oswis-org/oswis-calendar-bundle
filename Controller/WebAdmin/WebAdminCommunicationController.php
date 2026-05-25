<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ImapSyncState;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantIncomingMail;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantUnmatchedMail;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
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

    /**
     * Communication landing page — gives the admin one entry point with
     * counts, last-fetch info and links to every sub-feature of the module
     * (unmatched inbox, ad-hoc compose, IMAP refresh, mail config).
     */
    public function index(): Response
    {
        $unmatchedCount = (int) $this->em
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(ParticipantUnmatchedMail::class, 'u')
            ->getQuery()
            ->getSingleScalarResult();

        $unmatchedIn = (int) $this->em
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(ParticipantUnmatchedMail::class, 'u')
            ->where('u.direction = :dir')
            ->setParameter('dir', 'in')
            ->getQuery()
            ->getSingleScalarResult();

        $unmatchedOut = $unmatchedCount - $unmatchedIn;

        // ParticipantIncomingMail stores BOTH directions (IMAP-fetched mails).
        // Split by direction for an honest "Zařazené příchozí / odchozí" card.
        $matchedImapIn = (int) $this->em
            ->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ParticipantIncomingMail::class, 'm')
            ->where('m.direction = :dir')
            ->setParameter('dir', 'in')
            ->getQuery()
            ->getSingleScalarResult();
        $matchedImapOut = (int) $this->em
            ->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ParticipantIncomingMail::class, 'm')
            ->where('m.direction = :dir')
            ->setParameter('dir', 'out')
            ->getQuery()
            ->getSingleScalarResult();
        // OSWIS-sent system mails (activation, summary, payment confirmation,
        // ad-hoc). Always outgoing, always matched (have participant_id FK).
        $matchedSystem = (int) $this->em
            ->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ParticipantMail::class, 'm')
            ->where('m.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $syncStates = $this->em
            ->getRepository(ImapSyncState::class)
            ->findBy([], ['folder' => 'ASC']);

        return $this->render('@OswisOrgOswisCalendar/web_admin/communication/index.html.twig', [
            'page_title'      => 'Komunikace :: ADMIN',
            'pageTitle'       => 'Komunikace',
            'unmatchedTotal'  => $unmatchedCount,
            'unmatchedIn'     => $unmatchedIn,
            'unmatchedOut'   => $unmatchedOut,
            'matchedImapIn'   => $matchedImapIn,
            'matchedImapOut'  => $matchedImapOut,
            'matchedSystem'   => $matchedSystem,
            'syncStates'      => $syncStates,
        ]);
    }

    /**
     * Communication timeline lives on the participant detail page now —
     * keep this route as a BC redirect so any old bookmark / link still
     * lands the admin on the right page (anchored to the #komunikace
     * section).
     */
    public function timelinePage(int $participantId): Response
    {
        $this->em->find(Participant::class, $participantId)
            ?? throw $this->createNotFoundException();

        return $this->redirectToRoute(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'komunikace'],
        );
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
