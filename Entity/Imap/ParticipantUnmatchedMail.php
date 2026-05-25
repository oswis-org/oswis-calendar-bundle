<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Imap;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ParticipantUnmatchedMailRepository;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationDirection;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;

/**
 * Incoming mail fetched from IMAP that doesn't match any known participant.
 * Admin reviews these in the unmatched-mails admin UI and assigns them to
 * a participant (which converts them to ParticipantIncomingMail).
 */
#[Entity(repositoryClass: ParticipantUnmatchedMailRepository::class)]
#[Table(name: 'calendar_participant_unmatched_mail')]
class ParticipantUnmatchedMail
{
    use BasicTrait;

    #[Column(type: 'string', length: 255, unique: true)]
    protected string $messageId;

    #[Column(type: 'string', length: 32, enumType: CommunicationDirection::class)]
    protected CommunicationDirection $direction;

    #[Column(type: 'datetime', nullable: false)]
    protected DateTime $occurredAt;

    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $subject = null;

    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $fromAddress = null;

    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $fromName = null;

    #[Column(type: 'text', nullable: true, options: ['columnDefinition' => 'LONGTEXT DEFAULT NULL'])]
    protected ?string $toAddresses = null;

    #[Column(type: 'text', nullable: true, options: ['columnDefinition' => 'LONGTEXT DEFAULT NULL'])]
    protected ?string $body = null;

    #[Column(type: 'text', nullable: true, options: ['columnDefinition' => 'LONGTEXT DEFAULT NULL'])]
    protected ?string $bodyHtml = null;

    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $inReplyTo = null;

    #[Column(type: 'string', length: 100, nullable: true)]
    protected ?string $imapFolder = null;

    #[Column(type: 'integer', nullable: true)]
    protected ?int $imapUid = null;

    /**
     * Triage status set by the admin from the unmatched inbox.
     *  - 'unprocessed' (default) — waiting for admin decision
     *  - 'general'                — valid mail but not tied to any participant
     *  - 'spam'                   — spam / irrelevant, hidden from the inbox
     * Stored as plain string so a future status doesn't require an enum
     * migration; values are guarded server-side.
     */
    #[Column(type: 'string', length: 32, options: ['default' => 'unprocessed'])]
    protected string $status = 'unprocessed';

    public const STATUS_UNPROCESSED = 'unprocessed';
    public const STATUS_GENERAL = 'general';
    public const STATUS_SPAM = 'spam';
    public const STATUSES = [self::STATUS_UNPROCESSED, self::STATUS_GENERAL, self::STATUS_SPAM];

    public function __construct(
        string $messageId,
        CommunicationDirection $direction,
        DateTime $occurredAt,
    ) {
        $this->messageId = $messageId;
        $this->direction = $direction;
        $this->occurredAt = $occurredAt;
    }

    public function getMessageId(): string { return $this->messageId; }
    public function getDirection(): CommunicationDirection { return $this->direction; }
    public function getOccurredAt(): DateTime { return $this->occurredAt; }
    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $v): void { $this->subject = $v; }
    public function getFromAddress(): ?string { return $this->fromAddress; }
    public function setFromAddress(?string $v): void { $this->fromAddress = $v; }
    public function getFromName(): ?string { return $this->fromName; }
    public function setFromName(?string $v): void { $this->fromName = $v; }
    public function getToAddresses(): ?string { return $this->toAddresses; }
    public function setToAddresses(?string $v): void { $this->toAddresses = $v; }
    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $v): void { $this->body = $v; }
    public function getBodyHtml(): ?string { return $this->bodyHtml; }
    public function setBodyHtml(?string $v): void { $this->bodyHtml = $v; }
    public function getInReplyTo(): ?string { return $this->inReplyTo; }
    public function setInReplyTo(?string $v): void { $this->inReplyTo = $v; }
    public function getImapFolder(): ?string { return $this->imapFolder; }
    public function setImapFolder(?string $v): void { $this->imapFolder = $v; }
    public function getImapUid(): ?int { return $this->imapUid; }
    public function setImapUid(?int $v): void { $this->imapUid = $v; }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid unmatched-mail status "%s". Allowed: %s.',
                $status,
                implode(', ', self::STATUSES),
            ));
        }
        $this->status = $status;
    }
}
