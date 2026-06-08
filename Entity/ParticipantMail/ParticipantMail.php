<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMail;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationChannel;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationDirection;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Communication\CommunicationEntryInterface;

#[ApiResource(
    normalizationContext: ['groups' => ['app_user_mails_get'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['app_user_mails_post'], 'enable_max_depth' => true],
    security: "is_granted('ROLE_ADMIN')",
    filters: ['search']
)]
#[GetCollection(
    security: "is_granted('ROLE_ADMIN')",
    normalizationContext: ['groups' => ['app_user_mails_get'], 'enable_max_depth' => true]
)]
#[Post(
    security: "is_granted('ROLE_ADMIN')",
    denormalizationContext: ['groups' => ['app_user_mails_post'], 'enable_max_depth' => true]
)]
#[Get(
    security: "is_granted('ROLE_ADMIN')",
    normalizationContext: ['groups' => ['app_user_mail_get'], 'enable_max_depth' => true]
)]
#[Put(
    security: "is_granted('ROLE_ADMIN')",
    denormalizationContext: ['groups' => ['app_user_mail_put'], 'enable_max_depth' => true]
)]
#[Entity]
#[Table(name: 'calendar_participant_mail')]
#[Index(name: 'IDX_PARTICIPANT_MAIL_THREAD_KEY', columns: ['thread_key'])]
#[Index(name: 'IDX_PARTICIPANT_MAIL_BULK', columns: ['bulk_id'])]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant_mail')]
class ParticipantMail extends AbstractMail implements CommunicationEntryInterface
{
    public const TYPE_ACTIVATION_REQUEST = 'activation-request';
    public const TYPE_SUMMARY = 'summary';
    public const TYPE_PAYMENT = 'payment';

    #[ManyToOne(targetEntity: Participant::class, fetch: 'EAGER', inversedBy: 'eMails')]
    #[JoinColumn(name: 'participant_id', referencedColumnName: 'id')]
    protected ?Participant $participant = null;

    #[ManyToOne(targetEntity: ParticipantMailCategory::class, fetch: 'EAGER')]
    #[JoinColumn(name: 'participant_mail_category_id', referencedColumnName: 'id')]
    protected ?ParticipantMailCategory $participantMailCategory = null;

    #[ManyToOne(targetEntity: AppUser::class, fetch: 'EAGER')]
    #[JoinColumn(name: 'app_user_id', referencedColumnName: 'id')]
    protected ?AppUser $appUser = null;

    #[ManyToOne(targetEntity: ParticipantToken::class, fetch: 'EAGER')]
    #[JoinColumn(name: 'participant_token_id', referencedColumnName: 'id')]
    protected ?ParticipantToken $participantToken = null;

    /** Bulk this mail was sent as part of (ad-hoc bulk outbox), null for system/individual mails. */
    #[ManyToOne(targetEntity: ParticipantMailBulk::class)]
    #[JoinColumn(name: 'bulk_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?ParticipantMailBulk $bulk = null;

    #[\Doctrine\ORM\Mapping\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $threadKey = null;

    public function __construct(
        ?Participant $participant = null,
        ?AppUser $appUser = null,
        ?string $subject = null,
        ?string $type = null,
        ?ParticipantToken $token = null,
        ?string $messageId = null,
    ) {
        if ($appUser) {
            parent::__construct($subject, $appUser->getEmail(), $type, $appUser->getName(), $messageId);
        }
        $this->participantToken = $token;
        $this->participant = $participant;
        $this->appUser = $appUser;
        $this->ensureThreadKey();
    }

    public function isParticipant(?Participant $participant): bool
    {
        return $this->getParticipant() === $participant;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    /**
     * @param Participant|null $participant
     *
     * @throws NotImplementedException
     */
    public function setParticipant(?Participant $participant): void
    {
        if ($this->participant === $participant) {
            return;
        }
        if (null !== $this->participant && (null !== $this->getId() && null === $participant)) {
            // Do not allow to remove e-mail from participant if payment was already persisted.
            throw new NotImplementedException('změna účastníka', 'u zprávy');
        }
        if ($this->participant && $this->participant !== $participant) {
            $this->participant->removeEMail($this);
        }
        $this->participant = $participant;
        $participant?->addEMail($this);
    }

    public function getAppUser(): ?AppUser
    {
        return $this->appUser;
    }

    public function getParticipantToken(): ?ParticipantToken
    {
        return $this->participantToken;
    }

    public function getParticipantMailCategory(): ?ParticipantMailCategory
    {
        return $this->participantMailCategory;
    }

    public function setParticipantMailCategory(?ParticipantMailCategory $participantMailCategory): void
    {
        $this->participantMailCategory = $participantMailCategory;
    }

    public function getBulk(): ?ParticipantMailBulk
    {
        return $this->bulk;
    }

    public function setBulk(?ParticipantMailBulk $bulk): void
    {
        $this->bulk = $bulk;
    }

    public function getThreadKey(): ?string
    {
        return $this->threadKey;
    }

    public function setThreadKey(?string $threadKey): void
    {
        $this->threadKey = $threadKey;
    }

    /**
     * Populate threadKey from subject + recipient email if currently null.
     * Idempotent: called from constructor + backfill command.
     */
    public function ensureThreadKey(): void
    {
        if (null !== $this->threadKey) {
            return;
        }
        $email = $this->getAddress();
        if ((null === $email || '' === trim($email)) && null !== $this->participant?->getContact()) {
            $email = $this->participant->getContact()->getEmail();
        }
        $this->threadKey = \OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMail::computeThreadKey(
            $this->getSubject(),
            $email,
        );
    }

    // ---- CommunicationEntryInterface ----

    public function getOccurredAt(): ?\DateTimeInterface
    {
        return $this->getSent();
    }

    public function getDirection(): CommunicationDirection
    {
        return CommunicationDirection::OUT;
    }

    public function getChannel(): CommunicationChannel
    {
        return str_starts_with($this->getType() ?? '', 'ad-hoc-')
            ? CommunicationChannel::AD_HOC_MAIL
            : CommunicationChannel::SYSTEM_MAIL;
    }

    public function getSummary(): ?string
    {
        return null;
    }

    public function getBody(): ?string
    {
        return null;
    }

    public function getBodyHtml(): ?string
    {
        return null;
    }

    public function isPublicForParticipant(): bool
    {
        return true;
    }

    // getMessageId() satisfied by parent::getMessageID() — PHP method names are case-insensitive.

    public function getInReplyTo(): ?string
    {
        return null;
    }

    public function getAuthorAppUser(): ?AppUser
    {
        return null;
    }
}
