<?php

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailRepository;
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
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Create a queued bulk (snapshot of recipient IDs). Sends nothing.
     *
     * @param array<int> $participantIds
     */
    public function queue(string $subject, string $bodyHtml, array $participantIds, ?string $adminName = null): ParticipantMailBulk
    {
        $bulk = new ParticipantMailBulk($subject, $bodyHtml, $participantIds, $adminName);
        $this->em->persist($bulk);
        $this->em->flush();

        return $bulk;
    }

    /**
     * Drain up to $batchSize recipients of $bulk, starting from its cursor. Idempotent + resumable
     * (cursor advanced per recipient → a crash re-sends at most one). Marks the bulk done when the
     * cursor reaches the end.
     *
     * @return array{sent: int, failed: int, processed: int, total: int, done: bool}
     */
    public function drainBatch(ParticipantMailBulk $bulk, int $batchSize = 15): array
    {
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
    }

    /**
     * Twig context for rendering {@see AD_HOC_TEMPLATE} as a PREVIEW for one participant (the first
     * contact person, if any). Same shape the drain uses, so the preview is faithful. No send.
     *
     * @return array<string, mixed>
     */
    public function previewContext(Participant $participant, string $subject, string $bodyHtml, ?string $adminName): array
    {
        $contact = $participant->getContact();
        $appUser = null;
        foreach ($participant->getContactPersons(true) as $contactPerson) {
            if ($contactPerson instanceof AbstractContact && null !== $contactPerson->getAppUser()) {
                $appUser = $contactPerson->getAppUser();
                break;
            }
        }

        return [
            'participant'    => $participant,
            'appUser'        => $appUser,
            'contact'        => $contact,
            'salutationName' => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
            'subject'        => $subject,
            'bodyHtml'       => $bodyHtml,
            'adminName'      => $adminName,
            'type'           => 'ad-hoc-bulk-preview',
        ];
    }

    /**
     * Send the bulk's message to one participant's contact persons (Person = 1 address, Org = N).
     * Returns true if at least one address was actually delivered (isSent).
     */
    private function sendToParticipant(ParticipantMailBulk $bulk, Participant $participant): bool
    {
        $contact = $participant->getContact();
        $type = sprintf('ad-hoc-bulk-%d', $bulk->getId() ?? 0);
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
                $data = [
                    'participant'    => $participant,
                    'appUser'        => $appUser,
                    'contact'        => $contact,
                    'salutationName' => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
                    'subject'        => $bulk->getSubject(),
                    'bodyHtml'       => $bulk->getBodyHtml(),
                    'adminName'      => $bulk->getAdminName(),
                    'type'           => $type,
                ];
                $this->em->persist($participantMail);
                $this->mailService->sendEMail($participantMail, self::AD_HOC_TEMPLATE, $data);
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
     * @return array{sent: int, failed: int, processed: int, total: int, done: bool}
     */
    private function progress(ParticipantMailBulk $bulk, int $sent, int $failed): array
    {
        return [
            'sent'      => $sent,
            'failed'    => $failed,
            'processed' => $bulk->getProcessedCount(),
            'total'     => $bulk->getTotalCount(),
            'done'      => $bulk->isDone(),
        ];
    }
}
