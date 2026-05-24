<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Imap;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ParticipantIncomingMailRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationChannel;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationDirection;
use OswisOrg\OswisCoreBundle\Interfaces\Communication\CommunicationEntryInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;

/**
 * One incoming (or outgoing-from-mailbox) mail fetched from IMAP, matched
 * to a participant by sender address.
 *
 * Spec: docs/superpowers/specs/2026-05-24-communication-history-design.md §5 D.
 * Threading uses `threadKey` exactly like ParticipantMail.
 */
#[Entity(repositoryClass: ParticipantIncomingMailRepository::class)]
#[Table(name: 'calendar_participant_incoming_mail')]
class ParticipantIncomingMail implements CommunicationEntryInterface
{
    use BasicTrait;

    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'participant_id', referencedColumnName: 'id', nullable: false)]
    protected Participant $participant;

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

    #[Column(type: 'text', nullable: true)]
    protected ?string $body = null;

    #[Column(type: 'text', nullable: true)]
    protected ?string $bodyHtml = null;

    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $inReplyTo = null;

    #[Column(type: 'string', length: 64, nullable: true)]
    protected ?string $threadKey = null;

    #[Column(type: 'string', length: 100, nullable: true)]
    protected ?string $imapFolder = null;

    #[Column(type: 'integer', nullable: true)]
    protected ?int $imapUid = null;

    public function __construct(
        Participant $participant,
        string $messageId,
        CommunicationDirection $direction,
        DateTime $occurredAt,
    ) {
        $this->participant = $participant;
        $this->messageId = $messageId;
        $this->direction = $direction;
        $this->occurredAt = $occurredAt;
    }

    public function getParticipant(): ?object
    {
        return $this->participant;
    }

    public function getChannel(): CommunicationChannel
    {
        return CommunicationChannel::INCOMING_MAIL;
    }

    public function getDirection(): CommunicationDirection
    {
        return $this->direction;
    }

    public function getOccurredAt(): ?\DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): void
    {
        $this->subject = $subject;
    }

    public function getSummary(): ?string
    {
        return null === $this->body ? null : mb_substr($this->body, 0, 200);
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): void
    {
        $this->body = $body;
    }

    public function getBodyHtml(): ?string
    {
        return $this->bodyHtml;
    }

    public function setBodyHtml(?string $bodyHtml): void
    {
        $this->bodyHtml = $bodyHtml;
    }

    public function isPublicForParticipant(): bool
    {
        return true;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function setInReplyTo(?string $inReplyTo): void
    {
        $this->inReplyTo = $inReplyTo;
    }

    public function getThreadKey(): ?string
    {
        return $this->threadKey;
    }

    public function setThreadKey(?string $threadKey): void
    {
        $this->threadKey = $threadKey;
    }

    public function getAuthorAppUser(): ?AppUser
    {
        return null;
    }

    public function getFromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(?string $fromAddress): void
    {
        $this->fromAddress = $fromAddress;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): void
    {
        $this->fromName = $fromName;
    }

    public function getImapFolder(): ?string
    {
        return $this->imapFolder;
    }

    public function setImapFolder(?string $imapFolder): void
    {
        $this->imapFolder = $imapFolder;
    }

    public function getImapUid(): ?int
    {
        return $this->imapUid;
    }

    public function setImapUid(?int $imapUid): void
    {
        $this->imapUid = $imapUid;
    }
}
