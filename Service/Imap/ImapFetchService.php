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

    /**
     * Flush + clear the UnitOfWork every N processed messages. Without this the
     * whole per-folder sweep accumulates N mails (full HTML+plain bodies) plus
     * their EAGER-loaded matched Participant graphs in memory at once, which
     * exhausts the 512 MB FPM limit on the in-request /imap-refresh button
     * (observed: HTTP 500). Flushing also advances the UID watermark per batch,
     * so a timeout mid-sweep is resumable instead of re-pulling from scratch.
     */
    private const FLUSH_BATCH = 5;

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
     * @param  \DateTimeInterface|null  $since  Restricts the sweep on a
     *   fresh sync_state row to mails received after this date via IMAP
     *   `SEARCH SINCE`. Useful for first-run on a mailbox where we want
     *   last season's history but not the entire archive. Has no effect
     *   after the UID watermark is set on subsequent runs.
     *
     * @return array{enabled: bool, folders: array<string, array{fetched: int, matched: int, unmatched: int, lastUid: int, error?: string}>}
     */
    public function fetchAll(int $perFolderCap = 100, bool $initFromNow = false, ?\DateTimeInterface $since = null): array
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
                $report['folders'][$folderName] = $this->fetchFolder($folder, $folderName, $perFolderCap, $initFromNow, $since);
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
    private function fetchFolder(Folder $folder, string $folderName, int $cap, bool $initFromNow = false, ?\DateTimeInterface $since = null): array
    {
        $state = $this->syncStateRepository->getOrCreate($folderName);
        $lastUid = $state->getLastSeenUid();

        // First-run init: skip historical mail. Find the current MAX UID in the
        // folder, store it as lastSeenUid, and return without persisting any
        // bodies. Subsequent runs (with $initFromNow=false) only pull mail
        // received AFTER this initialisation.
        if ($lastUid === 0 && $initFromNow) {
            $maxMsg = $folder->messages()
                ->all()
                ->setFetchBody(false)
                ->setFetchFlags(false)
                ->leaveUnread()
                ->setFetchOrderDesc()
                ->limit(1)
                ->get()
                ->first();
            $maxUid = $maxMsg instanceof Message ? (int) $maxMsg->getUid() : 0;
            $state->setLastSeenUid($maxUid);
            $state->setLastFetchAt(new DateTime());
            $this->em->persist($state);
            $this->em->flush();
            $this->em->clear();

            return ['fetched' => 0, 'matched' => 0, 'unmatched' => 0, 'lastUid' => $maxUid];
        }

        // Chunked UID-range sweep — CRITICAL for memory. webklex loads the FULL
        // body (incl. attachments) of every message in a ->get() batch into RAM
        // at once and keeps the parsed Message objects for the batch's lifetime.
        // Pulling the whole per-folder cap (e.g. 30) in one batch ballooned a
        // single FPM worker to ~1.7 GB and got it cgroup-OOM-killed mid-request
        // (signal 9) — the /imap-refresh "405" was a stale-cache symptom of that
        // crash, NOT a routing problem. So we pull only FLUSH_BATCH messages per
        // IMAP round, persist + flush + clear + free them, then advance the UID
        // cursor and pull the next round. Peak memory is bounded to one chunk
        // regardless of $cap; FLUSH_BATCH alone (UnitOfWork clear) did NOT bound
        // the webklex message collection, hence the OOM persisted.
        // webklex query uses BODY.PEEK (leaveUnread() + setFetchFlags(false)) →
        // no \Seen mutation. WITHOUT the UID range, ->all()->limit(N) always
        // returns the lowest N UIDs which get skipped after the first round.
        $fetched = 0;
        $matched = 0;
        $unmatched = 0;
        $maxUid = $lastUid;
        $cursor = $lastUid;
        $firstRoundSince = (0 === $lastUid && $since instanceof \DateTimeInterface);

        // Per-fetch dedup set: (message_id, participant_id) pairs already
        // persisted in THIS run. The IMAP server can return the same
        // Message-ID across multiple UIDs (re-sent drafts, manual archive
        // copies in Sent, …) which would otherwise hit the DB unique key
        // and abort the whole folder fetch. Kept across rounds (small: strings).
        $persistedThisFetch = [];

        while ($fetched < $cap) {
            $chunkLimit = min(self::FLUSH_BATCH, $cap - $fetched);
            $query = $folder->messages()
                ->setFetchOrderAsc()
                ->setFetchBody(true)
                ->setFetchFlags(false)
                ->leaveUnread()
                ->limit($chunkLimit);
            if ($cursor > 0) {
                $query = $query->whereUid(sprintf('%d:*', $cursor + 1));
            } elseif ($firstRoundSince) {
                // First-run pulling at most last-season's history.
                $query = $query->whereSince($since);
            } else {
                $query = $query->all();
            }
            $messages = $query->get();

            $processedInRound = 0;
            foreach ($messages as $message) {
                if (!$message instanceof Message) {
                    continue;
                }
                $uid = (int) $message->getUid();
                if ($cursor > 0 && $uid <= $cursor) {
                    continue;
                }
                $processedInRound++;
                $fetched++;
                $maxUid = max($maxUid, $uid);

                $messageId = (string) $message->getMessageId();
                if ('' === $messageId) {
                    $messageId = sprintf('uid-%s-%d@oswis.local', $folderName, $uid);
                }
                $messageId = trim($messageId, "<> \t\n\r\0\x0B");

                // Dedup: unmatched already imported with this Message-ID → skip.
                // (Incoming is per-participant, so its dedup happens per recipient below.)
                if ($this->unmatchedRepository->findOneByMessageId($messageId)) {
                    continue;
                }

                $subject = $this->decodeMimeHeader((string) $message->getSubject());
                try {
                    $occurredAt = DateTime::createFromInterface($message->getDate()->toDate());
                } catch (\Throwable) {
                    $occurredAt = new DateTime();
                }
                $bodyPlain = (string) $message->getTextBody();
                $bodyHtml = (string) $message->getHtmlBody();
                $fromObj = $message->getFrom()->first();
                $fromAddress = null;
                $rawFromName = '';
                if (is_object($fromObj)) {
                    if (property_exists($fromObj, 'mail') && is_string($fromObj->mail)) {
                        $fromAddress = $fromObj->mail;
                    }
                    if (method_exists($fromObj, 'getPersonal')) {
                        $personal = $fromObj->getPersonal();
                        $rawFromName = is_string($personal) ? $personal : '';
                    } elseif (property_exists($fromObj, 'personal') && is_string($fromObj->personal)) {
                        $rawFromName = $fromObj->personal;
                    }
                }
                $fromName = '' !== $rawFromName ? $this->decodeMimeHeader($rawFromName) : null;
                $inReplyTo = trim((string) $message->getInReplyTo(), "<> \t\n\r\0\x0B") ?: null;

                $direction = $this->detectDirection($fromAddress, $folderName);
                $threadKey = AbstractMail::computeThreadKey($subject, $fromAddress);

                $participants = $this->matchParticipants($fromAddress, $direction, $message);

                if (count($participants) > 0) {
                    // One row per matched participant — for OUT mails to multiple
                    // recipients, each participant gets the entry in their timeline.
                    foreach ($participants as $participant) {
                        $pid = $participant->getId();
                        if (null === $pid) {
                            continue;
                        }
                        // Per-fetch dedup: messageId can repeat across UIDs in
                        // Sent (re-sent drafts, archive copies). Cross-batch
                        // dedup against stored rows handles previous runs.
                        $batchKey = $messageId.'|'.$pid;
                        if (isset($persistedThisFetch[$batchKey])) {
                            continue;
                        }
                        if ($this->incomingRepository->findOneByMessageIdAndParticipant($messageId, $participant)) {
                            $persistedThisFetch[$batchKey] = true;
                            continue;
                        }
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
                        $persistedThisFetch[$batchKey] = true;
                        $matched++;
                    }
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
                    $entry->setToAddresses(implode(', ', $this->extractRecipientEmails($message)));
                    $this->em->persist($entry);
                    $unmatched++;
                }

            }
            // Round complete: persist + advance the UID watermark, flush, clear,
            // and free the chunk (webklex messages + their EAGER participant
            // graphs) before the next IMAP pull. Watermark advances per round →
            // a crash/timeout mid-sweep is resumable, not re-pulled from scratch.
            $state->setLastSeenUid($maxUid);
            $state->setLastFetchAt(new DateTime());
            $this->em->persist($state);
            $this->em->flush();
            // DO NOT em->clear() here. clear() detaches the security actor
            // (the authenticated AppUser from the token). The next Blameable
            // prePersist then sees an UNMANAGED actor, and Gedmo re-persist()s it
            // (AbstractTrackingListener::updateField, "if field value is reference,
            // persist object"). Because AppUser is itself Blameable and references
            // the same actor, that persist re-enters prePersist → persist → …
            // infinitely → the worker balloons and is cgroup-OOM-killed (~12s,
            // SIGKILL). THAT recursion — not message accumulation — was the real
            // /imap-refresh failure (the "405" was a stale-cache symptom of the
            // crash). Chunked fetch + unset($messages) already bounds the heavy
            // webklex body/attachment memory; the Doctrine UoW holds only the
            // lightweight mail rows for this cap, so keeping the actor managed
            // (no clear) is both correct and memory-safe. CLI never hit this
            // because there is no security token there (getActor() === null).
            unset($messages);

            // Fewer messages than requested → no more mail in the UID range, stop.
            if ($processedInRound < $chunkLimit) {
                break;
            }
        }

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
     *
     * Returns ALL matched participants, not just the first. For OUT mails
     * to N recipients, each matching participant gets the entry in their
     * timeline. Phase D dedup uses (message_id + participant_id) so the
     * same Message-ID can legitimately link to multiple participants.
     *
     * @return list<Participant>
     */
    private function matchParticipants(?string $fromAddress, CommunicationDirection $direction, Message $message): array
    {
        $candidates = [];
        if ($direction === CommunicationDirection::IN && null !== $fromAddress) {
            $candidates[] = strtolower($fromAddress);
        }
        if ($direction === CommunicationDirection::OUT) {
            $candidates = array_merge($candidates, $this->extractRecipientEmails($message));
        }
        if (count($candidates) === 0) {
            return [];
        }

        $matched = [];
        $seenIds = [];
        foreach ($candidates as $email) {
            $contact = $this->findContactByEmail($email);
            if (!$contact instanceof AbstractContact) {
                continue;
            }
            $participants = $this->em->getRepository(Participant::class)
                ->findBy(['contact' => $contact], ['createdAt' => 'DESC'], 1);
            if (count($participants) > 0) {
                $id = $participants[0]->getId();
                if (null !== $id && !isset($seenIds[$id])) {
                    $matched[] = $participants[0];
                    $seenIds[$id] = true;
                }
            }
        }

        return $matched;
    }

    /**
     * Extract recipient e-mails from an IMAP Message.
     *
     * Defensive: webklex's `getTo()` may return an empty Collection on
     * Sent-folder mails where the To-header is in alternate form (rare
     * but observed) or only the raw `toaddress` string is populated.
     * Try `getTo()` first, fall back to parsing the comma-separated
     * `toaddress` attribute, fall back to Cc/Bcc as a last resort.
     *
     * @return list<string>
     */
    private function extractRecipientEmails(Message $message): array
    {
        $emails = [];

        foreach (['getTo', 'getCc', 'getBcc'] as $accessor) {
            try {
                $collection = $message->{$accessor}()->all();
            } catch (\Throwable) {
                continue;
            }
            foreach ($collection as $addr) {
                if (!is_object($addr)) {
                    continue;
                }
                $rawMail = '';
                if (property_exists($addr, 'mail') && is_string($addr->mail)) {
                    $rawMail = $addr->mail;
                }
                if ('' === $rawMail) {
                    $mailbox = property_exists($addr, 'mailbox') && is_string($addr->mailbox) ? $addr->mailbox : '';
                    $host = property_exists($addr, 'host') && is_string($addr->host) ? $addr->host : '';
                    if ('' !== $mailbox && '' !== $host) {
                        $rawMail = $mailbox.'@'.$host;
                    }
                }
                $mail = strtolower(trim($rawMail));
                if ('' !== $mail && false !== strpos($mail, '@')) {
                    $emails[] = $mail;
                }
            }
            if (count($emails) > 0) {
                break;
            }
        }

        if (count($emails) === 0) {
            // Last-resort: parse the comma-separated raw toaddress attribute.
            $raw = '';
            try {
                $rawAttr = $message->get('toaddress');
                if (is_string($rawAttr)) {
                    $raw = $rawAttr;
                } elseif (is_object($rawAttr) && method_exists($rawAttr, '__toString')) {
                    $raw = (string) $rawAttr;
                }
            } catch (\Throwable) {
                // ignore
            }
            if ('' !== $raw && preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $raw, $m)) {
                foreach ($m[0] as $addr) {
                    $emails[] = strtolower($addr);
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Decode a MIME-encoded header (RFC 2047) like
     * "=?UTF-8?B?UmU6IHpkcmF2b3Ruw61r?=" → "Re: zdravotník".
     *
     * `iconv_mime_decode_headers` would return arrays for multi-field headers;
     * for a single value we use iconv_mime_decode which is more predictable.
     * If decoding fails for any reason, fall back to the raw input rather
     * than throwing.
     */
    private function decodeMimeHeader(string $raw): string
    {
        if ('' === $raw) {
            return '';
        }
        // Quick check: if there's no MIME-encoded-word marker, skip the decode.
        if (!str_contains($raw, '=?')) {
            return $raw;
        }
        $decoded = @iconv_mime_decode($raw, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return false === $decoded ? $raw : $decoded;
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
