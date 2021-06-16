<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagAmountRange;
use OswisOrg\OswisCalendarBundle\Traits\Entity\FlagAmountRangeTrait;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use OswisOrg\OswisCoreBundle\Traits\Form\FormValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_flag_group_range")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_flag_group_ranges_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_flag_group_ranges_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_flag_group_range_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_flag_group_range_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_flag_range")
 */
class FlagGroupRange implements NameableInterface
{
    use NameableTrait {
        getName as traitGetName;
        getShortName as traitGetShortName;
        getDescription as traitGetDescription;
        getNote as traitGetNote;
    }
    use EntityPublicTrait;
    use FlagAmountRangeTrait;
    use FormValueTrait;
    use TextValueTrait;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?string $emptyPlaceholder = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\FlagCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?FlagCategory $flagCategory = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\FlagRange", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_flag_group_range_flag_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="flag_group_range_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="flag_range_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $flagRanges = null;

    public function __construct(?FlagCategory $category = null, ?FlagAmountRange $flagAmountRange = null, ?Publicity $publicity = null, ?string $emptyPlaceholder = null)
    {
        $this->flagRanges = new ArrayCollection();
        $this->setFlagCategory($category);
        $this->setFlagAmountRange($flagAmountRange);
        $this->setFieldsFromPublicity($publicity);
        $this->emptyPlaceholder = $emptyPlaceholder;
    }

    public function getEmptyPlaceholder(): ?string
    {
        return $this->emptyPlaceholder;
    }

    public function setEmptyPlaceholder(?string $emptyPlaceholder): void
    {
        $this->emptyPlaceholder = $emptyPlaceholder;
    }

    public function addFlagRange(?FlagRange $flagRange): void
    {
        if (null !== $flagRange && !$this->getFlagRanges()->contains($flagRange)) {
            $this->getFlagRanges()->add($flagRange);
        }
    }

    public function getFlagRanges(bool $onlyPublic = false, Flag $flag = null): Collection
    {
        $flagRanges = $this->flagRanges ?? new ArrayCollection();
        if (true === $onlyPublic) {
            $flagRanges = $flagRanges->filter(fn(FlagRange $flagRange) => $flagRange->isPublicOnWeb());
        }
        if (null !== $flag) {
            $flagRanges = $flagRanges->filter(fn(FlagRange $flagRange) => $flagRange->getFlag() === $flag);
        }

        return $flagRanges;
    }

    /**
     * @param  FlagRange|null  $flagRange
     *
     * @throws NotImplementedException
     */
    public function removeFlagRange(?FlagRange $flagRange): void
    {
        if (null !== $flagRange) {
            throw new NotImplementedException('odebrání rozsahu příznaku ze skupiny', 'u rozsahu příznaků');
        }
    }

    public function isCategory(?FlagCategory $category = null): bool
    {
        return null === $category || ($this->getFlagCategory() && $this->getFlagCategory() === $category);
    }

    public function getFlagCategory(): ?FlagCategory
    {
        return $this->flagCategory;
    }

    public function setFlagCategory(?FlagCategory $flagCategory): void
    {
        $this->flagCategory = $flagCategory;
    }

    public function isType(?string $flagType = null): bool
    {
        return null === $flagType || $this->getType() === $flagType;
    }

    public function getType(): ?string
    {
        return $this->getFlagCategory()?->getType();
    }

    public function isCategoryValueAllowed(): bool
    {
        return $this->isFormValueAllowed();
    }

    public function isFlagValueAllowed(bool $onlyPublic = false, ?Flag $flag = null): bool
    {
        return $this->getFlagRanges($onlyPublic, $flag)->filter(fn(FlagRange $flagRange) => $flagRange->isFormValueAllowed())->count() > 0;
    }

    public function hasFlagValueAllowed(): bool
    {
        return $this->getFlagRanges()->exists(fn(FlagRange $flagRange) => $flagRange->isFormValueAllowed());
    }

    public function getFlagGroupName(): ?string
    {
        return $this->getName() ?? $this->getFlagCategory()?->getName();
    }

    public function getName(): ?string
    {
        return $this->traitGetName() ?? $this->getFlagCategory()?->getName();
    }

    public function getFlagsGroupNames(): array
    {
        $groups = [];
        foreach ($this->getFlagRanges(true) as $flagRange) {
            $groupName = $flagRange->getFlagGroupName();
            $groups[$groupName] = ($groups[$groupName] ?? 0) + 1;
        }

        return $groups;
    }

    public function getShortName(): ?string
    {
        return $this->traitGetShortName() ?? $this->getFlagCategory()?->getShortName();
    }

    public function getDescription(): string
    {
        return $this->traitGetDescription() ?? $this->getFlagCategory()?->getDescription() ?? '';
    }

    public function getNote(): string
    {
        return $this->traitGetNote() ?? $this->getFlagCategory()?->getNote() ?? '';
    }
}
