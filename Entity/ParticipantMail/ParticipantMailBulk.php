<?php

/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailBulkRepository;

/**
 * Durable outbox for ad-hoc bulk e-mails composed in web admin. A bulk row holds the message (subject
 * + verbatim HTML body) plus a snapshot of recipient participant IDs; it is drained in capped batches
 * by a cron command / JS auto-drain (synchronous SMTP can't loop over hundreds in one request).
 *
 * Deliberately a PLAIN entity — no Gedmo Blameable relation. It is persisted/updated from a CLI drain
 * where the Blameable security actor is absent; a Blameable relation there triggered the persist
 * recursion class that caused the IMAP OOM. The composing admin is stored as a plain string instead.
 *
 * @author Jakub Zak <mail@jakubzak.eu>
 */
#[Entity(repositoryClass: ParticipantMailBulkRepository::class)]
#[Table(name: 'calendar_participant_mail_bulk')]
#[Index(name: 'IDX_PARTICIPANT_MAIL_BULK_STATUS', columns: ['status'])]
class ParticipantMailBulk
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_DONE = 'done';

    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    protected ?int $id = null;

    #[Column(type: 'string', length: 255)]
    protected string $subject;

    #[Column(type: 'text')]
    protected string $bodyHtml;

    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $adminName = null;

    #[Column(type: 'datetime')]
    protected DateTime $createdAt;

    #[Column(type: 'string', length: 16)]
    protected string $status = self::STATUS_QUEUED;

    /** @var list<int> Snapshot of recipient participant IDs (resolved from the list filter/selection at compose time). */
    #[Column(type: 'json')]
    protected array $participantIds = [];

    /** Cursor into participantIds — how many have been processed (sent or failed). Resumable. */
    #[Column(type: 'integer')]
    protected int $processedCount = 0;

    #[Column(type: 'integer')]
    protected int $sentCount = 0;

    #[Column(type: 'integer')]
    protected int $failedCount = 0;

    #[Column(type: 'text', nullable: true)]
    protected ?string $failedNote = null;

    /**
     * @param array<int> $participantIds normalized to a 0-indexed list (callers may pass filtered/keyed arrays)
     */
    public function __construct(string $subject, string $bodyHtml, array $participantIds, ?string $adminName = null)
    {
        $this->subject = $subject;
        $this->bodyHtml = $bodyHtml;
        $this->participantIds = array_values($participantIds);
        $this->adminName = $adminName;
        $this->createdAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function getAdminName(): ?string
    {
        return $this->adminName;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isDone(): bool
    {
        return self::STATUS_DONE === $this->status;
    }

    /** @return list<int> */
    public function getParticipantIds(): array
    {
        return $this->participantIds;
    }

    public function getTotalCount(): int
    {
        return count($this->participantIds);
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function setProcessedCount(int $processedCount): void
    {
        $this->processedCount = max(0, $processedCount);
    }

    public function getRemainingCount(): int
    {
        return max(0, $this->getTotalCount() - $this->processedCount);
    }

    public function getSentCount(): int
    {
        return $this->sentCount;
    }

    public function recordSent(): void
    {
        ++$this->sentCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function recordFailed(?string $note = null): void
    {
        ++$this->failedCount;
        if (null !== $note && '' !== trim($note)) {
            $this->failedNote = mb_substr(trim(($this->failedNote ?? '')."\n".trim($note)), 0, 60000);
        }
    }

    public function getFailedNote(): ?string
    {
        return $this->failedNote;
    }
}
