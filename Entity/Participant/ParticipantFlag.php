<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantFlagRepository;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Filter\SearchFilter;
use OswisOrg\OswisCoreBundle\Interfaces\Common\ActivatedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\TextValueInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActivatedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use Symfony\Component\Serializer\Annotation\MaxDepth;

/**
 * RegistrationFlag assigned to event participant (i.e. special food requirement...) through some "flag range".
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_participant_flags_get"},
 *     "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_participant_flags_post"},
 *     "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_participant_flag_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_participant_flag_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 */
#[Entity(repositoryClass: ParticipantFlagRepository::class)]
#[Table(name: 'calendar_participant_flag')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class ParticipantFlag implements BasicInterface, DeletedInterface, ActivatedInterface, TextValueInterface
{
    use BasicTrait;
    use TextValueTrait;
    use ActivatedTrait;
    use DeletedTrait;

    /** Event contact flag. */
    #[ManyToOne(targetEntity: RegistrationFlagOffer::class, fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    protected ?RegistrationFlagOffer $flagOffer = null;

    #[ManyToOne(targetEntity: ParticipantFlagGroup::class, fetch: 'EAGER', inversedBy: 'participantFlags')]
    #[JoinColumn(nullable: true)]
    #[MaxDepth(1)]
    protected ?ParticipantFlagGroup $participantFlagGroup = null;

    /** Date and time of invalidation/use of flag. */
    #[Column(type: Types::DATETIME_MUTABLE, nullable: true, options: ['default' => null])]
    #[ApiFilter(SearchFilter::class, strategy: 'ipartial')]
    #[ApiFilter(OrderFilter::class)]
    #[ApiFilter(DateFilter::class)]
    protected ?DateTime $invalidatedAt = null;

    public function __construct(
        ?RegistrationFlagOffer $flagRange = null,
        ParticipantFlagGroup $participantFlagGroup = null,
        ?string $textValue = null
    ) {
        try {
            $this->setParticipantFlagGroup($participantFlagGroup);
        } catch (NotImplementedException) {
        }
        $this->setFlagOffer($flagRange);
        $this->setTextValue($textValue);
    }

    public function invalidate(): void
    {
        if (!$this->isInvalidated()) {
            $this->invalidatedAt = new DateTime();
        }
    }

    public function isInvalidated(?DateTime $dateTime = null): bool
    {
        return $this->getInvalidatedAt() && ($this->getInvalidatedAt() <= ($dateTime ?? new DateTime()));
    }

    public function getInvalidatedAt(): ?DateTime
    {
        return $this->invalidatedAt;
    }

    public function setInvalidatedAt(?DateTime $dateTime = null): void
    {
        $this->invalidatedAt = $dateTime ? clone $dateTime : null;
    }

    public function getFlagType(): ?string
    {
        return $this->getFlagOffer()?->getType();
    }

    public function getFlagOffer(): ?RegistrationFlagOffer
    {
        return $this->flagOffer;
    }

    public function setFlagOffer(?RegistrationFlagOffer $flagOffer): void
    {
        if ($this->flagOffer === $flagOffer) {
            return;
        }
        if (null === $this->flagOffer) {
            $this->flagOffer = $flagOffer;
        }
    }

    public function getColor(): ?string
    {
        return $this->getFlag()?->getColor();
    }

    public function getFlag(): ?RegistrationFlag
    {
        return $this->getFlagOffer()?->getFlag();
    }

    public function getFlagCategory(): ?RegistrationFlagCategory
    {
        return $this->getFlagOffer()?->getCategory();
    }

    public function getPrice(): int
    {
        return $this->isActive() ? ($this->getFlagOffer()?->getPrice() ?? 0) : 0;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->isActivated() && !$this->isDeleted($referenceDateTime);
    }

    public function getDepositValue(): int
    {
        return $this->isActive() ? ($this->getFlagOffer()?->getDepositValue() ?? 0) : 0;
    }

    public function getParticipantFlagGroup(): ?ParticipantFlagGroup
    {
        return $this->participantFlagGroup;
    }

    /**
     * @param ParticipantFlagGroup|null $participantFlagGroup
     *
     * @throws NotImplementedException
     */
    public function setParticipantFlagGroup(?ParticipantFlagGroup $participantFlagGroup): void
    {
        if ($this->participantFlagGroup && $participantFlagGroup !== $this->participantFlagGroup) {
            throw new NotImplementedException('změna skupiny', 'u použití příznaku');
        }
        $this->participantFlagGroup = $participantFlagGroup;
        if ($this->participantFlagGroup && $participantFlagGroup) {
            $this->participantFlagGroup->getParticipantFlags()->add($this);
        }
    }

    public function getName(): ?string
    {
        return $this->getFlagOffer()?->getName();
    }

    public function getShortName(): ?string
    {
        return $this->getFlagOffer()?->getShortName();
    }

    public function getExtendedName(): ?string
    {
        return $this->getFlagOffer()?->getExtendedName(true, false);
    }
}
