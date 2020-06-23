<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractEMail;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMail;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

/**
 * E-mail sent to some user included in participant.
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_mail")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_ADMIN')",
 *     "normalization_context"={"groups"={"app_user_e_mails_get"}, "enable_max_depth"=true},
 *     "denormalization_context"={"groups"={"app_user_e_mails_post"}, "enable_max_depth"=true}
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"app_user_e_mails_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"app_user_e_mails_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"app_user_e_mail_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"app_user_e_mail_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "token"
 * })
 * @author Jakub Zak <mail@jakubzak.eu>
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="core_app_user")
 */
class ParticipantMail extends AbstractMail
{
    public const TYPE_ACTIVATION_REQUEST = 'activation-request';
    public const TYPE_ACTIVATION = 'activation';

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\Participant", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")
     */
    protected ?Participant $participant = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="app_user_id", referencedColumnName="id")
     */
    protected ?AppUser $appUser = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCoreBundle\Entity\AppUser\AppUserToken", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="app_user_token_id", referencedColumnName="id")
     */
    protected ?ParticipantToken $participantToken = null;

    public function __construct(
        Participant $participant,
        AppUser $appUser,
        string $subject,
        ?string $type = null,
        ParticipantToken $token = null,
        ?string $messageId = null
    ) {
        parent::__construct($subject, $this->appUser->getEmail(), $type, $this->appUser->getName(), $messageId);
        $this->participantToken = $token;
        $this->participant = $participant;
        $this->appUser = $appUser;
    }

    public static function getAllowedTypesDefault(): array
    {
        return [...parent::getAllowedTypesDefault(), self::TYPE_ACTIVATION_REQUEST, self::TYPE_ACTIVATION];
    }

    public function isParticipant(?Participant $participant): bool
    {
        return $this->getParticipant() === $participant;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function getAppUser(): ?AppUser
    {
        return $this->appUser;
    }

    public function getParticipantToken(): ?ParticipantToken
    {
        return $this->participantToken;
    }
}
