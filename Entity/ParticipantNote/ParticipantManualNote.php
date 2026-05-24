<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantNote;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantNote\ParticipantManualNoteRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationChannel;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationDirection;
use OswisOrg\OswisCoreBundle\Interfaces\Communication\CommunicationEntryInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;

/**
 * Manual communication log entry recorded by an admin: phone call, chat
 * snapshot, in-person interaction, etc.
 *
 * Phase B (Komponenta B) of the communication-history spec — unified table
 * spanning the spec's phone/chat distinction for launch-day simplicity.
 * The `channel` field tags which kind of interaction this is (PHONE / CHAT /
 * INCOMING_MAIL / AD_HOC_MAIL).
 *
 * Spec: docs/superpowers/specs/2026-05-24-communication-history-design.md §5 B.
 */
#[Entity(repositoryClass: ParticipantManualNoteRepository::class)]
#[Table(name: 'calendar_participant_manual_note')]
class ParticipantManualNote implements CommunicationEntryInterface
{
    use BasicTrait;

    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'participant_id', referencedColumnName: 'id', nullable: false)]
    protected Participant $participant;

    #[Column(type: 'string', length: 32, enumType: CommunicationChannel::class)]
    protected CommunicationChannel $channel;

    #[Column(type: 'string', length: 16, enumType: CommunicationDirection::class)]
    protected CommunicationDirection $direction;

    #[Column(type: 'datetime', nullable: false)]
    protected DateTime $occurredAt;

    #[Column(type: 'string', length: 200, nullable: true)]
    protected ?string $subject = null;

    #[Column(type: 'string', length: 200, nullable: true)]
    protected ?string $otherPartyName = null;

    #[Column(type: 'string', length: 100, nullable: true)]
    protected ?string $otherPartyContact = null;

    #[Column(type: 'integer', nullable: true)]
    protected ?int $durationSec = null;

    #[Column(type: 'text', nullable: true, options: ['columnDefinition' => 'LONGTEXT DEFAULT NULL'])]
    protected ?string $body = null;

    #[Column(name: 'is_internal', type: 'boolean', options: ['default' => true])]
    protected bool $internal = true;

    #[ManyToOne(targetEntity: AppUser::class)]
    #[JoinColumn(name: 'author_app_user_id', referencedColumnName: 'id', nullable: true)]
    protected ?AppUser $authorAppUser = null;

    #[Column(type: 'string', length: 64, nullable: true)]
    protected ?string $threadKey = null;

    public function __construct(
        Participant $participant,
        CommunicationChannel $channel,
        CommunicationDirection $direction,
        DateTime $occurredAt,
        ?string $subject = null,
        ?string $body = null,
        bool $internal = true,
        ?AppUser $authorAppUser = null,
        ?string $otherPartyName = null,
        ?string $otherPartyContact = null,
        ?int $durationSec = null,
    ) {
        $this->participant = $participant;
        $this->channel = $channel;
        $this->direction = $direction;
        $this->occurredAt = $occurredAt;
        $this->subject = $subject;
        $this->body = $body;
        $this->internal = $internal;
        $this->authorAppUser = $authorAppUser;
        $this->otherPartyName = $otherPartyName;
        $this->otherPartyContact = $otherPartyContact;
        $this->durationSec = $durationSec;
    }

    public function getParticipant(): ?object
    {
        return $this->participant;
    }

    public function getChannel(): CommunicationChannel
    {
        return $this->channel;
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

    public function getSummary(): ?string
    {
        return null === $this->body ? null : mb_substr($this->body, 0, 200);
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getBodyHtml(): ?string
    {
        return null;
    }

    public function isPublicForParticipant(): bool
    {
        return !$this->internal;
    }

    public function getMessageId(): ?string
    {
        return null;
    }

    public function getInReplyTo(): ?string
    {
        return null;
    }

    public function getThreadKey(): ?string
    {
        return $this->threadKey;
    }

    public function getAuthorAppUser(): ?AppUser
    {
        return $this->authorAppUser;
    }

    public function getOtherPartyName(): ?string
    {
        return $this->otherPartyName;
    }

    public function getOtherPartyContact(): ?string
    {
        return $this->otherPartyContact;
    }

    public function getDurationSec(): ?int
    {
        return $this->durationSec;
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }
}
