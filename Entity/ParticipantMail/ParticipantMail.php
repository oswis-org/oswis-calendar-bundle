<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMail;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;

/**
 * E-mail sent to some user included in participant.
 * @author Jakub Zak <mail@jakubzak.eu>
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "token"
 * })
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_ADMIN')",
 *     "normalization_context"={"groups"={"app_user_mails_get"}, "enable_max_depth"=true},
 *     "denormalization_context"={"groups"={"app_user_mails_post"}, "enable_max_depth"=true}
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"app_user_mails_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"app_user_mails_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"app_user_mail_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"app_user_mail_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 */
#[Entity]
#[Table(name: 'calendar_participant_mail')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant_mail')]
class ParticipantMail extends AbstractMail
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

    public function __construct(
        Participant $participant = null,
        AppUser $appUser = null,
        string $subject = null,
        ?string $type = null,
        ParticipantToken $token = null,
        ?string $messageId = null
    ) {
        if ($appUser) {
            parent::__construct($subject, $appUser->getEmail(), $type, $appUser->getName(), $messageId);
        }
        $this->participantToken = $token;
        $this->participant = $participant;
        $this->appUser = $appUser;
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
     * @param  Participant|null  $participant
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
}
