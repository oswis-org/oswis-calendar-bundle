<?php

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailRepository;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Service\MailService;
use Psr\Log\LoggerInterface;

/**
 * Queue + drain of the ad-hoc bulk e-mail outbox ({@see ParticipantMailBulk}). Sending is synchronous
 * blocking SMTP, so a bulk is drained in capped batches (cron command / JS auto-drain), never in one
 * request. Per-recipient send replicates {@see ParticipantMailService::sendAdHoc} but tags each mail
 * with the bulk (audit), is failure-aware (counts only real isSent deliveries), and advances the bulk
 * cursor per recipient so a crash re-sends at most one.
 */
class ParticipantBulkMailService
{
    public const AD_HOC_TEMPLATE = '@OswisOrgOswisCalendar/e-mail/pages/participant-ad-hoc.html.twig';

    public function __construct(
        protected EntityManagerInterface $em,
        protected MailService $mailService,
        protected ParticipantMailRepository $participantMailRepository,
        protected MailPreviewService $mailPreview,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Create a queued bulk (snapshot of recipient IDs). Sends nothing. The optional $templateSlug
     * selects a stored campaign/snippet to send instead of the free body. Queue-validation renders the
     * message against the first recipient and throws {@see OswisException} if the Twig won't compile —
     * a typo is caught here, never delivered to real recipients via the drain.
     *
     * @param array<int> $participantIds
     *
     * @throws OswisException when the message fails to render against the first recipient
     */
    public function queue(
        string $subject,
        string $bodyHtml,
        array $participantIds,
        ?string $adminName = null,
        ?string $templateSlug = null,
    ): ParticipantMailBulk {
        $this->validateRender($bodyHtml, $templateSlug, $participantIds);
        $bulk = new ParticipantMailBulk($subject, $bodyHtml, $participantIds, $adminName, $templateSlug);
        $this->em->persist($bulk);
        $this->em->flush();

        return $bulk;
    }

    /**
     * Render the message (stored template, or free body fragment) against the FIRST recipient and throw
     * on a Twig error. With no resolvable recipient there is nothing to validate (the empty-recipient
     * guard lives in the controller). {@see queue}.
     *
     * @param array<int> $participantIds
     *
     * @throws OswisException
     */
    private function validateRender(string $bodyHtml, ?string $templateSlug, array $participantIds): void
    {
        $firstId = array_values($participantIds)[0] ?? null;
        $participant = null !== $firstId ? $this->em->find(Participant::class, (int) $firstId) : null;
        if (!$participant instanceof Participant) {
            return;
        }
        try {
            if (null !== $templateSlug && '' !== trim($templateSlug)) {
                $result = $this->mailPreview->renderTemplate(trim($templateSlug), $participant);
                if (null !== $result['error']) {
                    throw new OswisException($result['error']);
                }
            } else {
                $this->mailPreview->renderBodyFragment($bodyHtml, $participant);
            }
        } catch (OswisException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new OswisException($exception->getMessage());
        }
    }

    /**
     * Drain up to $batchSize recipients of $bulk, starting from its cursor. Idempotent + resumable
     * (cursor advanced per recipient → a crash re-sends at most one). Marks the bulk done when the
     * cursor reaches the end.
     *
     * Concurrency: the status-page JS auto-drain and the cron command (and two admin tabs) can call
     * this for the SAME bulk at the same time — two drains reading the same cursor would send the
     * same slice twice (duplicate mail to real recipients). A per-bulk MariaDB advisory lock
     * (GET_LOCK, non-blocking, connection-scoped, auto-released on disconnect) serializes drains
     * without holding a DB transaction across SMTP sends. A caller that does not get the lock
     * returns immediately with busy=true and NO progress (the JS backs off and re-polls; the cron
     * breaks on zero progress and resumes next tick). The entity is refresh()ed AFTER acquiring the
     * lock — the caller may hold a cursor that another drain has meanwhile advanced.
     *
     * @return array{sent: int, failed: int, processed: int, total: int, done: bool, busy: bool}
     */
    public function drainBatch(ParticipantMailBulk $bulk, int $batchSize = 15): array
    {
        if ($bulk->isDone()) {
            return $this->progress($bulk, 0, 0);
        }
        $lockName = sprintf('oswis_bulk_drain_%d', $bulk->getId() ?? 0);
        $connection = $this->em->getConnection();
        $acquired = $connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]);
        if (!is_numeric($acquired) || 1 !== (int) $acquired) {
            $this->logger->info(sprintf('Bulk #%d: drain already running elsewhere, skipping.', $bulk->getId() ?? 0));

            return $this->progress($bulk, 0, 0, busy: true);
        }
        try {
            // Fresh cursor: our entity may predate a drain that just finished on another connection.
            $this->em->refresh($bulk);
            if ($bulk->isDone()) {
                return $this->progress($bulk, 0, 0);
            }
            if (ParticipantMailBulk::STATUS_SENDING !== $bulk->getStatus()) {
                $bulk->setStatus(ParticipantMailBulk::STATUS_SENDING);
                $this->em->flush();
            }
            $ids = $bulk->getParticipantIds();
            $start = $bulk->getProcessedCount();
            $slice = array_slice($ids, $start, max(1, $batchSize));
            $sent = 0;
            $failed = 0;

            foreach ($slice as $position => $participantId) {
                $participant = $this->em->find(Participant::class, $participantId);
                $delivered = $participant instanceof Participant && $this->sendToParticipant($bulk, $participant);
                if ($delivered) {
                    $bulk->recordSent();
                    ++$sent;
                } else {
                    $bulk->recordFailed(sprintf('#%d: nedoručeno', (int) $participantId));
                    ++$failed;
                }
                $bulk->setProcessedCount($start + (int) $position + 1);
                $this->em->flush();
            }

            if ($bulk->getProcessedCount() >= $bulk->getTotalCount()) {
                $bulk->setStatus(ParticipantMailBulk::STATUS_DONE);
                $this->em->flush();
            }

            return $this->progress($bulk, $sent, $failed);
        } finally {
            $connection->executeQuery('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * Send the bulk's message to one participant's contact persons (Person = 1 address, Org = N).
     * Renders per recipient: a stored campaign/snippet template (template_slug) as a full mail, OR the
     * free body as a trusted Twig fragment (entity-API variables + conditional blocks) into the ad-hoc
     * wrapper. A body render error for one recipient falls back to the raw (sanitized) body + a log
     * line, so one recipient missing a field never blocks the rest of the bulk. Returns true if at
     * least one address was actually delivered (isSent).
     */
    private function sendToParticipant(ParticipantMailBulk $bulk, Participant $participant): bool
    {
        $type = sprintf('ad-hoc-bulk-%d', $bulk->getId() ?? 0);
        $usesTemplate = $bulk->hasTemplate();
        $anyDelivered = false;

        foreach ($participant->getContactPersons(true) as $contactPerson) {
            if (!$contactPerson instanceof AbstractContact) {
                continue;
            }
            $appUser = $contactPerson->getAppUser();
            if (null === $appUser) {
                continue;
            }
            try {
                $participantMail = new ParticipantMail($participant, $appUser, $bulk->getSubject(), $type);
                $participantMail->setBulk($bulk);
                $participantMail->setPastMails($this->participantMailRepository->findByParticipant($participant));
                $participantMail->markAsManual();

                if ($usesTemplate) {
                    $templateName = (string) $bulk->getTemplateSlug();
                    $data = $this->mailPreview->buildContext($participant, [
                        'appUser'   => $appUser,
                        'adminName' => $bulk->getAdminName(),
                        'type'      => $type,
                    ]);
                } else {
                    try {
                        $renderedBody = $this->mailPreview->renderBodyFragment(
                            $bulk->getBodyHtml(),
                            $participant,
                            ['appUser' => $appUser],
                        );
                    } catch (\Throwable $bodyError) {
                        $renderedBody = $this->mailPreview->sanitizeHtml($bulk->getBodyHtml());
                        $this->logger->warning(sprintf(
                            'Bulk #%d → participant #%d: body Twig render failed, raw fallback used: %s',
                            $bulk->getId() ?? 0,
                            $participant->getId() ?? 0,
                            $bodyError->getMessage(),
                        ));
                    }
                    $templateName = self::AD_HOC_TEMPLATE;
                    $data = $this->mailPreview->buildContext($participant, [
                        'appUser'   => $appUser,
                        'adminName' => $bulk->getAdminName(),
                        'type'      => $type,
                        'bodyHtml'  => $renderedBody,
                    ]);
                }

                $this->em->persist($participantMail);
                $this->mailService->sendEMail($participantMail, $templateName, $data);
                if ($participantMail->isSent()) {
                    $anyDelivered = true;
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Bulk #%d → participant #%d (%s): %s',
                    $bulk->getId() ?? 0,
                    $participant->getId() ?? 0,
                    (string) $appUser->getEmail(),
                    $e->getMessage(),
                ));
            }
        }

        return $anyDelivered;
    }

    /**
     * @return array{sent: int, failed: int, processed: int, total: int, done: bool, busy: bool}
     */
    private function progress(ParticipantMailBulk $bulk, int $sent, int $failed, bool $busy = false): array
    {
        return [
            'sent'      => $sent,
            'failed'    => $failed,
            'processed' => $bulk->getProcessedCount(),
            'total'     => $bulk->getTotalCount(),
            'done'      => $bulk->isDone(),
            'busy'      => $busy,
        ];
    }
}
