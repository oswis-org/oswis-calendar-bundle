<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Imap;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ImapSyncState;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantIncomingMail;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantUnmatchedMail;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ImapSyncStateRepository;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ParticipantIncomingMailRepository;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ParticipantUnmatchedMailRepository;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMail;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationDirection;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Read-only IMAP fetcher. Pulls new mails (incremental by UID) from the
 * configured folders, matches each to a participant by sender e-mail, and
 * persists matched as ParticipantIncomingMail / unmatched as
 * ParticipantUnmatchedMail.
 *
 * READ-ONLY GUARANTEES (per memory `feedback_imap_read_only.md`):
 *   - Uses BODY.PEEK[] under the hood (webklex/php-imap default for getBody()).
 *   - Never marks messages as \Seen, \Flagged, or any flag.
 *   - Never moves, copies, deletes, or expunges.
 *   - Tracks "what's already been fetched" in ImapSyncState (DB), NOT in IMAP flags.
 *
 * Per memory `feedback_no_extra_server_daemons` — sync usage (called from
 * console command or admin "refresh" button), no daemon.
 */
final class ImapFetchService
{
    public const FROM_DOMAIN_OWN = 'seznamovakup.cz';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ImapSyncStateRepository $syncStateRepository,
        private readonly ParticipantIncomingMailRepository $incomingRepository,
        private readonly ParticipantUnmatchedMailRepository $unmatchedRepository,
        private readonly string $imapHost,
        private readonly int $imapPort,
        private readonly string $imapEncryption,
        private readonly string $imapUser,
        private readonly string $imapPassword,
        private readonly string $foldersToSync,
        private readonly bool $enabled,
    ) {
    }

    /**
     * @return array{enabled: bool, folders: array<string, array{fetched: int, matched: int, unmatched: int, lastUid: int}>}
     */
    public function fetchAll(int $perFolderCap = 100): array
    {
        if (!$this->enabled) {
            $this->logger->info('IMAP fetch disabled (OSWIS_IMAP_ENABLED=0); skipping.');

            return ['enabled' => false, 'folders' => []];
        }

        $cm = new ClientManager();
        $client = $cm->make([
            'host'          => $this->imapHost,
            'port'          => $this->imapPort,
            'encryption'    => $this->imapEncryption,
            'validate_cert' => true,
            'username'      => $this->imapUser,
            'password'      => $this->imapPassword,
            'protocol'      => 'imap',
        ]);
        $client->connect();

        $folderNames = array_filter(array_map('trim', explode(',', $this->foldersToSync)));
        $report = ['enabled' => true, 'folders' => []];

        foreach ($folderNames as $folderName) {
            try {
                $folder = $client->getFolderByPath($folderName);
                if (!$folder instanceof Folder) {
                    $this->logger->warning(sprintf('IMAP folder "%s" not found, skipping.', $folderName));
                    continue;
                }
                $report['folders'][$folderName] = $this->fetchFolder($folder, $folderName, $perFolderCap);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('IMAP fetch on folder "%s" failed: %s', $folderName, $e->getMessage()));
                $report['folders'][$folderName] = ['fetched' => 0, 'matched' => 0, 'unmatched' => 0, 'lastUid' => 0, 'error' => $e->getMessage()];
            }
        }

        try {
            $client->disconnect();
        } catch (\Throwable) {
            // Ignore disconnect errors — read-only session, no state to preserve.
        }

        return $report;
    }

    /**
     * @return array{fetched: int, matched: int, unmatched: int, lastUid: int}
     */
    private function fetchFolder(Folder $folder, string $folderName, int $cap): array
    {
        $state = $this->syncStateRepository->getOrCreate($folderName);
        $lastUid = $state->getLastSeenUid();

        // Per-folder UID-range search — UID > lastSeenUid; webklex's query uses
        // BODY.PEEK by default (leaveUnread() + setFetchFlags(false)) → no
        // \Seen state mutation. Cap per iteration.
        // WITHOUT the UID range, ->all()->limit(100) always returns the lowest
        // 100 UIDs which get skipped after first fetch — so new mails past
        // the 100th total message never get pulled.
        $query = $folder->messages()
            ->setFetchOrderAsc()
            ->setFetchBody(true)
            ->setFetchFlags(false)
            ->leaveUnread()
            ->limit($cap);
        if ($lastUid > 0) {
            $query = $query->whereUid(sprintf('%d:*', $lastUid + 1));
        } else {
            $query = $query->all();
        }
        $messages = $query->get();

        $fetched = 0;
        $matched = 0;
        $unmatched = 0;
        $maxUid = $lastUid;

        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                continue;
            }
            $uid = (int) $message->getUid();
            if ($uid <= $lastUid) {
                continue;
            }
            $fetched++;
            $maxUid = max($maxUid, $uid);

            $messageId = (string) $message->getMessageId();
            if ('' === $messageId) {
                $messageId = sprintf('uid-%s-%d@oswis.local', $folderName, $uid);
            }
            $messageId = trim($messageId, "<> \t\n\r\0\x0B");

            if ($this->incomingRepository->findOneByMessageId($messageId)
                || $this->unmatchedRepository->findOneByMessageId($messageId)) {
                continue;
            }

            $subject = (string) $message->getSubject();
            $occurredAt = $message->getDate()->first()?->toDate() ?? new DateTime();
            if (!$occurredAt instanceof DateTime) {
                $occurredAt = new DateTime((string) $occurredAt->format('c'));
            }
            $bodyPlain = (string) $message->getTextBody();
            $bodyHtml = (string) $message->getHtmlBody();
            $fromObj = $message->getFrom()->first();
            $fromAddress = $fromObj ? (string) $fromObj->mail : null;
            $fromName = $fromObj && method_exists($fromObj, 'getPersonal')
                ? (string) $fromObj->getPersonal()
                : ($fromObj && property_exists($fromObj, 'personal') ? (string) $fromObj->personal : null);
            $inReplyTo = trim((string) $message->getInReplyTo(), "<> \t\n\r\0\x0B") ?: null;

            $direction = $this->detectDirection($fromAddress, $folderName);
            $threadKey = AbstractMail::computeThreadKey($subject, $fromAddress);

            $participant = $this->matchParticipant($fromAddress, $direction, $message);

            if ($participant instanceof Participant) {
                $entry = new ParticipantIncomingMail(
                    participant: $participant,
                    messageId:   $messageId,
                    direction:   $direction,
                    occurredAt:  $occurredAt,
                );
                $entry->setSubject(mb_substr($subject, 0, 255));
                $entry->setBody($bodyPlain);
                $entry->setBodyHtml($bodyHtml);
                $entry->setFromAddress(null !== $fromAddress ? mb_substr($fromAddress, 0, 255) : null);
                $entry->setFromName(null !== $fromName ? mb_substr($fromName, 0, 255) : null);
                $entry->setInReplyTo(null !== $inReplyTo ? mb_substr($inReplyTo, 0, 255) : null);
                $entry->setThreadKey($threadKey);
                $entry->setImapFolder($folderName);
                $entry->setImapUid($uid);
                $this->em->persist($entry);
                $matched++;
            } else {
                $entry = new ParticipantUnmatchedMail(
                    messageId:  $messageId,
                    direction:  $direction,
                    occurredAt: $occurredAt,
                );
                $entry->setSubject(mb_substr($subject, 0, 255));
                $entry->setBody($bodyPlain);
                $entry->setBodyHtml($bodyHtml);
                $entry->setFromAddress(null !== $fromAddress ? mb_substr($fromAddress, 0, 255) : null);
                $entry->setFromName(null !== $fromName ? mb_substr($fromName, 0, 255) : null);
                $entry->setInReplyTo(null !== $inReplyTo ? mb_substr($inReplyTo, 0, 255) : null);
                $entry->setImapFolder($folderName);
                $entry->setImapUid($uid);
                $toAddrs = [];
                foreach ($message->getTo() as $t) {
                    $toAddrs[] = (string) $t->mail;
                }
                $entry->setToAddresses(implode(', ', array_filter($toAddrs)));
                $this->em->persist($entry);
                $unmatched++;
            }
        }

        $state->setLastSeenUid($maxUid);
        $state->setLastFetchAt(new DateTime());
        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        return ['fetched' => $fetched, 'matched' => $matched, 'unmatched' => $unmatched, 'lastUid' => $maxUid];
    }

    private function detectDirection(?string $fromAddress, string $folderName): CommunicationDirection
    {
        if (null !== $fromAddress && str_ends_with(strtolower($fromAddress), '@'.self::FROM_DOMAIN_OWN)) {
            return CommunicationDirection::OUT;
        }
        if (0 === strcasecmp($folderName, 'Sent')) {
            return CommunicationDirection::OUT;
        }

        return CommunicationDirection::IN;
    }

    /**
     * Match by sender e-mail (incoming) or recipient (outgoing).
     */
    private function matchParticipant(?string $fromAddress, CommunicationDirection $direction, Message $message): ?Participant
    {
        $candidates = [];
        if ($direction === CommunicationDirection::IN && null !== $fromAddress) {
            $candidates[] = strtolower($fromAddress);
        }
        if ($direction === CommunicationDirection::OUT) {
            foreach ($message->getTo() as $to) {
                $mail = strtolower((string) $to->mail);
                if ('' !== $mail) {
                    $candidates[] = $mail;
                }
            }
        }
        if (count($candidates) === 0) {
            return null;
        }

        foreach ($candidates as $email) {
            $contact = $this->findContactByEmail($email);
            if (!$contact instanceof AbstractContact) {
                continue;
            }
            $participants = $this->em->getRepository(Participant::class)
                ->findBy(['contact' => $contact], ['createdAt' => 'DESC'], 1);
            if (count($participants) > 0 && $participants[0] instanceof Participant) {
                return $participants[0];
            }
        }

        return null;
    }

    /**
     * Resolve a Contact by e-mail. AbstractContact stores e-mails in the
     * `details` collection (ContactDetail rows with category type = 'email'),
     * not as a direct column — so a `findOneBy(['email' => ...])` does not work.
     */
    private function findContactByEmail(string $email): ?AbstractContact
    {
        $qb = $this->em->createQueryBuilder()
            ->select('contact')
            ->from(AbstractContact::class, 'contact')
            ->innerJoin('contact.details', 'detail')
            ->innerJoin('detail.detailCategory', 'cat')
            ->andWhere('LOWER(detail.content) = :email')
            ->andWhere('cat.type = :emailType')
            ->setParameter('email', $email)
            ->setParameter('emailType', \OswisOrg\OswisAddressBookBundle\Entity\ContactDetailCategory::TYPE_EMAIL)
            ->setMaxResults(1);
        $result = $qb->getQuery()->getOneOrNullResult();

        return $result instanceof AbstractContact ? $result : null;
    }
}
