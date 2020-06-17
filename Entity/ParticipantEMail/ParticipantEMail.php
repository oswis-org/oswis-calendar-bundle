<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantEMail;

use ApiPlatform\Core\Annotation\ApiResource;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractEMail;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

/**
 * E-mail sent to some user included in participant.
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="core_app_user_e_mail")
 * @ApiResource(
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
 * @Searchable({
 *     "id",
 *     "token"
 * })
 * @author Jakub Zak <mail@jakubzak.eu>
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="core_app_user")
 */
class ParticipantEMail extends AbstractEMail
{
    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\Participant", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")
     */
    protected ?Participant $participant = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCoreBundle\Entity\AppUser\AppUserToken", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="app_user_token_id", referencedColumnName="id")
     */
    protected ?ParticipantToken $participantToken = null;

    /**
     * @param Participant|null      $participant
     * @param Nameable|null         $nameable
     * @param string|null           $eMail
     * @param string|null           $type
     * @param ParticipantToken|null $token
     *
     * @throws OswisException|InvalidTypeException
     */
    public function __construct(
        ?Participant $participant = null,
        ?Nameable $nameable = null,
        ?string $eMail = null,
        ?string $type = null,
        ParticipantToken $token = null
    ) {
        parent::__construct($nameable, $eMail, $type);
        $this->setParticipantToken($token);
        $this->participant = $participant;
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
     * @param AppUser|null $participant
     *
     * @throws OswisException
     */
    public function setParticipant(?AppUser $participant): void
    {
        if (null === $participant || null === $this->getParticipant()) {
            $this->participant = $participant;
        }
        throw new OswisException('nelze změnit uživatele u odeslaného e-mailu');
    }

    public function getParticipantToken(): ?ParticipantToken
    {
        return $this->participantToken;
    }

    /**
     * @param ParticipantToken|null $participantToken
     *
     * @throws OswisException
     */
    public function setParticipantToken(?ParticipantToken $participantToken): void
    {
        if (null === $participantToken || null === $this->getParticipantToken()) {
            $this->participantToken = $participantToken;
        }
        throw new OswisException('nelze změnit přiřazený token u odeslaného e-mailu');
    }
}
