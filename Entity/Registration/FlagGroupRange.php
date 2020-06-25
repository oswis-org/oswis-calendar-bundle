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
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_flag_range")
 */
class FlagGroupRange implements NameableInterface
{
    use NameableTrait;
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

    public function __construct(?FlagCategory $category, ?FlagAmountRange $flagAmountRange = null, ?Publicity $publicity = null, ?string $emptyPlaceholder = null)
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
     * @param FlagRange|null $flagRange
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
        return null === $category ? true : $this->getFlagCategory() && $this->getFlagCategory() === $category;
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
        return null === $flagType ? true : $this->getType() === $flagType;
    }

    public function getType(): ?string
    {
        return $this->getFlagCategory() ? $this->getFlagCategory()->getType() : null;
    }

    public function isCategoryValueAllowed(): bool
    {
        return $this->isFormValueAllowed();
    }

    public function hasFlagValueAllowed(): bool
    {
        return $this->getFlagRanges()->exists(fn(FlagRange $flagRange) => $flagRange->isFormValueAllowed());
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
}
