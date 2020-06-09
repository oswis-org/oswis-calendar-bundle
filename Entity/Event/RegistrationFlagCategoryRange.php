<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagAmountRange;
use OswisOrg\OswisCalendarBundle\Traits\Entity\FlagAmountRangeTrait;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use OswisOrg\OswisCoreBundle\Traits\Form\FormValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_registration_flag_category_range")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class RegistrationFlagCategoryRange implements NameableInterface
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
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationFlagCategory $category = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagRange", mappedBy="categoryRange")
     */
    protected ?Collection $flagRanges = null;

    public function __construct(
        ?RegistrationFlagCategory $category,
        ?FlagAmountRange $flagAmountRange = null,
        ?Publicity $publicity = null,
        ?string $emptyPlaceholder = null
    ) {
        $this->flagRanges = new ArrayCollection();
        $this->setCategory($category);
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

    public function addFlagRange(?RegistrationFlagRange $flagRange): void
    {
        if (null !== $flagRange && !$this->getFlagRanges()->contains($flagRange)) {
            $this->getFlagRanges()->add($flagRange);
            $flagRange->setCategoryRange($this);
        }
    }

    public function removeFlagRange(?RegistrationFlagRange $flagRange): void
    {
        if (null !== $flagRange && $this->getFlagRanges()->removeElement($flagRange)) {
            $flagRange->setCategoryRange(null);
        }
    }

    public function getFlagRanges(bool $onlyPublic = false): Collection
    {
        $flagRanges = $this->flagRanges ?? new ArrayCollection();
        if ($onlyPublic) {
            $flagRanges = $flagRanges->filter(fn(RegistrationFlagRange $flagRange) => $flagRange->isPublicOnWeb());
        }

        return $flagRanges;
    }

    public function getCategory(): ?RegistrationFlagCategory
    {
        return $this->category;
    }

    public function setCategory(?RegistrationFlagCategory $category): void
    {
        $this->category = $category;
    }

    public function isCategory(?RegistrationFlagCategory $category = null): bool
    {
        return null === $category ? true : $this->getCategory() && $this->getCategory() === $category;
    }

    public function getType(): ?string
    {
        return $this->getCategory() ? $this->getCategory()->getType() : null;
    }

    public function isType(?string $flagType = null): bool
    {
        return null === $flagType ? true : $this->getType() === $flagType;
    }

    public function isCategoryValueAllowed(): bool
    {
        return $this->isFormValueAllowed();
    }

    public function hasFlagValueAllowed(): bool
    {
        return $this->getFlagRanges()->exists(fn(RegistrationFlagRange $flagRange) => $flagRange->isFormValueAllowed());
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
