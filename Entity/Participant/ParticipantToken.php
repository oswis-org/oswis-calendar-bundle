<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantTokenRepository;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractToken;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

/**
 * @author Jakub Zak <mail@jakubzak.eu>
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "token"
 * })
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_ADMIN')",
 *     "normalization_context"={"groups"={"participant_tokens_get"}, "enable_max_depth"=true},
 *     "denormalization_context"={"groups"={"participant_tokens_post"}, "enable_max_depth"=true}
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"participant_tokens_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"participant_tokens_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"participant_token_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"participant_token_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 */
#[Entity(repositoryClass : ParticipantTokenRepository::class)]
#[Table(name : 'calendar_participant_token')]
#[Cache(usage : 'NONSTRICT_READ_WRITE', region : 'calendar_participant')]
class ParticipantToken extends AbstractToken
{
    #[ManyToOne(targetEntity : Participant::class, fetch : 'EAGER')]
    #[JoinColumn(name : 'participant_id', referencedColumnName : 'id')]
    protected ?Participant $participant = null;

    #[ManyToOne(targetEntity : AppUser::class, fetch : 'EAGER')]
    #[JoinColumn(name : 'app_user_id', referencedColumnName : 'id')]
    protected ?AppUser $appUser = null;

    public function __construct(
        ?Participant $participant = null,
        ?AppUser $appUser = null,
        ?string $type = null,
        bool $multipleUseAllowed = false,
        ?int $validHours = null,
        ?int $level = null
    ) {
        parent::__construct($appUser?->getEmail(), $type, $multipleUseAllowed, $validHours, $level);
        $this->appUser     = $appUser;
        $this->participant = $participant;
    }

    public function isParticipant(Participant $participant): bool
    {
        return $this->getParticipant() === $participant;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function isAppUser(AppUser $appUser): bool
    {
        return $this->getAppUser() === $appUser;
    }

    public function getAppUser(): ?AppUser
    {
        return $this->appUser;
    }
}
