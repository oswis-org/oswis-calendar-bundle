<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantIncomingMail;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantUnmatchedMail;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ParticipantUnmatchedMailRepository;
use OswisOrg\OswisCalendarBundle\Service\Imap\ImapFetchService;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase D admin UI:
 *  - GET  /web_admin/komunikace/nezarazene   — list unmatched mails.
 *  - POST /web_admin/komunikace/nezarazene/{id}/priradit/{participantId} — assign one.
 *  - POST /web_admin/komunikace/nezarazene/{id}/smazat — drop one (spam).
 *  - POST /web_admin/komunikace/imap-refresh — sync IMAP now (returns to referer).
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminImapController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImapFetchService $imapFetchService,
        private readonly ParticipantUnmatchedMailRepository $unmatchedRepository,
    ) {
    }

    public function listUnmatched(Request $request): Response
    {
        $statusFilter = $request->query->get('status', ParticipantUnmatchedMail::STATUS_UNPROCESSED);
        if (!in_array($statusFilter, ParticipantUnmatchedMail::STATUSES, true) && $statusFilter !== 'all') {
            $statusFilter = ParticipantUnmatchedMail::STATUS_UNPROCESSED;
        }
        $criteria = $statusFilter === 'all' ? [] : ['status' => $statusFilter];
        $unmatched = $this->unmatchedRepository->findBy($criteria, ['occurredAt' => 'DESC'], 200);

        return $this->render('@OswisOrgOswisCalendar/web_admin/communication/unmatched.html.twig', [
            'unmatched'    => $unmatched,
            'statusFilter' => $statusFilter,
            'pageTitle'    => 'Nezařazené e-maily (IMAP)',
            'page_title'   => 'Nezařazené e-maily (IMAP) :: ADMIN',
        ]);
    }

    /**
     * Bulk-mark several unmatched mails as `general` (valid but no participant
     * link) or `spam`. Soft state — no DB delete — so the admin can revisit
     * the filter and reclassify.
     */
    public function bulkMark(Request $request): Response
    {
        $action = (string) $request->request->get('action');
        if (!$this->isCsrfTokenValid('unmatched_bulk', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $allowed = [
            ParticipantUnmatchedMail::STATUS_GENERAL,
            ParticipantUnmatchedMail::STATUS_SPAM,
            ParticipantUnmatchedMail::STATUS_UNPROCESSED,
        ];
        if (!in_array($action, $allowed, true)) {
            $this->addFlash('error', 'Neplatná hromadná akce.');

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
        }
        $ids = $request->request->all('mail_ids');
        if ($ids === []) {
            $this->addFlash('warning', 'Nebyly vybrány žádné e-maily.');

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
        }
        $intIds = array_values(array_unique(array_map(
            static fn ($id): int => is_scalar($id) ? (int) $id : 0,
            $ids,
        )));
        $intIds = array_filter($intIds, static fn (int $id): bool => $id > 0);
        $count = 0;
        foreach ($intIds as $mailId) {
            $mail = $this->em->find(ParticipantUnmatchedMail::class, $mailId);
            if (!$mail instanceof ParticipantUnmatchedMail) {
                continue;
            }
            $mail->setStatus($action);
            $count++;
        }
        $this->em->flush();
        $this->addFlash('success', sprintf('Hromadně označeno %d e-mailů jako %s.', $count, $action));

        return new RedirectResponse($request->headers->get('referer')
            ?? $this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
    }

    public function assignUnmatched(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('unmatched_assign_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participantId = (int) $request->request->get('participantId');
        if ($participantId <= 0) {
            $this->addFlash('error', 'Zadej platné ID účastníka.');

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
        }
        $unmatched = $this->em->find(ParticipantUnmatchedMail::class, $id)
            ?? throw $this->createNotFoundException('E-mail nenalezen.');
        $participant = $this->em->find(Participant::class, $participantId)
            ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $incoming = new ParticipantIncomingMail(
            participant: $participant,
            messageId:   $unmatched->getMessageId(),
            direction:   $unmatched->getDirection(),
            occurredAt:  $unmatched->getOccurredAt(),
        );
        $incoming->setSubject($unmatched->getSubject());
        $incoming->setBody($unmatched->getBody());
        $incoming->setBodyHtml($unmatched->getBodyHtml());
        $incoming->setFromAddress($unmatched->getFromAddress());
        $incoming->setFromName($unmatched->getFromName());
        $incoming->setInReplyTo($unmatched->getInReplyTo());
        $incoming->setThreadKey(AbstractMail::computeThreadKey(
            $unmatched->getSubject() ?? '',
            $unmatched->getFromAddress(),
        ));
        $incoming->setImapFolder($unmatched->getImapFolder());
        $incoming->setImapUid($unmatched->getImapUid());

        $this->em->persist($incoming);
        $this->em->remove($unmatched);
        try {
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'E-mail #%d přiřazen účastníkovi #%d.',
                $id, $participantId,
            ));
        } catch (UniqueConstraintViolationException) {
            // Same (message_id, participant_id) already exists in
            // calendar_participant_incoming_mail (typical cause: IMAP sync
            // re-import raced with this assign action, or the admin double
            // -clicked). Just drop the unmatched row — the mail is already
            // linked to THIS participant.
            $this->em->clear();
            $unmatchedFresh = $this->em->find(ParticipantUnmatchedMail::class, $id);
            if ($unmatchedFresh instanceof ParticipantUnmatchedMail) {
                $this->em->remove($unmatchedFresh);
                $this->em->flush();
            }
            $this->addFlash('warning', sprintf(
                'E-mail #%d byl už dříve přiřazen (Message-ID duplikát). Nezařazený záznam smazán.',
                $id,
            ));
        }

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
    }

    public function deleteUnmatched(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('unmatched_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $unmatched = $this->em->find(ParticipantUnmatchedMail::class, $id)
            ?? throw $this->createNotFoundException('E-mail nenalezen.');
        $this->em->remove($unmatched);
        $this->em->flush();

        $this->addFlash('warning', sprintf('Nezařazený e-mail #%d smazán (spam-handling).', $id));

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
    }

    public function refresh(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('imap_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        // Lower cap than the cron command (100). In-request IMAP fetch must
        // stay under the 30s PHP max_execution_time — 30 messages per folder
        // is the sweet spot for hosting90's IMAP latency.
        $report = $this->imapFetchService->fetchAll(30);

        if (!$report['enabled']) {
            $this->addFlash('warning', 'IMAP fetch vypnutý (OSWIS_IMAP_ENABLED=0).');
        } else {
            $totalMatched = 0;
            $totalUnmatched = 0;
            $errors = [];
            foreach ($report['folders'] as $folder => $stats) {
                $totalMatched += $stats['matched'];
                $totalUnmatched += $stats['unmatched'];
                if (!empty($stats['error'])) {
                    $errors[] = "$folder: ".$stats['error'];
                }
            }
            $this->addFlash('success', sprintf(
                'IMAP sync: %d nových matched, %d unmatched%s.',
                $totalMatched,
                $totalUnmatched,
                $errors === [] ? '' : ' — chyby: '.implode(' | ', $errors),
            ));
        }

        $referer = $request->headers->get('referer');
        if (is_string($referer) && '' !== $referer) {
            return new RedirectResponse($referer);
        }

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
    }
}
