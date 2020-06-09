<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagAmountRange;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCalendarBundle\Traits\Entity\CapacityTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\CapacityUsageTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\FlagAmountRangeTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\PriceTrait;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\FormValue;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Form\FormValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_registration_flag_range")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class RegistrationFlagRange implements NameableInterface
{
    use NameableTrait {
        getName as traitGetName;
        getShortName as traitGetShortName;
    }
    use EntityPublicTrait;
    use CapacityTrait;
    use PriceTrait;
    use CapacityUsageTrait;
    use FlagAmountRangeTrait;
    use FormValueTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagCategoryRange", inversedBy="flagRanges", fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationFlagCategoryRange $categoryRange = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlag", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationFlag $flag = null;

    public function __construct(
        ?RegistrationFlag $flag = null,
        ?Capacity $eventCapacity = null,
        ?Price $eventPrice = null,
        ?FlagAmountRange $flagAmountRange = null,
        ?Publicity $publicity = null,
        ?FormValue $formValue = null
    ) {
        $this->setFlag($flag);
        $this->setCapacity($eventCapacity);
        $this->setEventPrice($eventPrice);
        $this->setFlagAmountRange($flagAmountRange);
        $this->setFieldsFromPublicity($publicity);
        $this->setFormValue($formValue ?? new FormValue(null, null));
    }

    public function getCategoryRange(): ?RegistrationFlagCategoryRange
    {
        return $this->categoryRange;
    }

    public function setCategoryRange(?RegistrationFlagCategoryRange $categoryRange): void
    {
        if ($this->categoryRange && $categoryRange !== $this->categoryRange) {
            $this->categoryRange->removeFlagRange($this);
        }
        $this->categoryRange = $categoryRange;
        if ($this->categoryRange) {
            $this->categoryRange->addFlagRange($this);
        }
    }

    public function getPrice(): int
    {
        return $this->price ?? 0;
    }

    public function getDepositValue(): int
    {
        return $this->depositValue ?? 0;
    }

    public function hasRemainingCapacity(): bool
    {
        return 0 === $this->getRemainingCapacity(false);
    }

    public function getRemainingCapacity(bool $full = false): ?int
    {
        $capacity = $this->getCapacityInt($full);

        return null === $capacity ? null : ($capacity - $this->getUsageInt($full));
    }

    public function isFlag(?RegistrationFlag $flag = null): bool
    {
        return null === $flag ? true : $this->getFlag() && $this->getFlag() === $flag;
    }

    public function getFlag(): ?RegistrationFlag
    {
        return $this->flag;
    }

    public function setFlag(?RegistrationFlag $flag): void
    {
        $this->flag = $flag;
    }

    public function isCategory(?RegistrationFlagCategory $category = null): bool
    {
        return null === $category ? true : $this->getCategory() && $this->getCategory() === $category;
    }

    public function getType(): ?string
    {
        return $this->getFlag() ? $this->getFlag()->getType() : null;
    }

    public function getCategory(): ?RegistrationFlagCategory
    {
        return $this->getFlag() ? $this->getFlag()->getCategory() : null;
    }

    public function isType(?string $flagType = null): bool
    {
        return null === $flagType ? true : $this->getType() === $flagType;
    }

    public function getExtendedName(bool $addPrice = true, bool $addCapacityOverflow = true): ?string
    {
        $flagName = $this->getName();
        if ($addPrice) {
            $price = $this->getPrice();
            $flagName .= 0 !== $price ? ' ['.($price > 0 ? '+' : '').$price.',- Kč]' : '';
        }
        if ($addCapacityOverflow && !$this->hasRemainingCapacity()) {
            $flagName .= ' (kapacita vyčerpána)';
        }

        return $flagName;
    }

    public function getFlagGroupName(): ?string
    {
        return RegistrationFlagRangeCategory::TYPE_T_SHIRT === $this->getType() ? $this->getTShirtGroup() : $this->getFlagPriceGroup();
    }

    public function getTShirtGroup(): string
    {
        $flagName = $this->getName();
        if (strpos($flagName, 'Pán') !== false) {
            return '♂ Pánské tričko';
        }
        if (strpos($flagName, 'Dám') !== false) {
            return '♀ Dámské tričko';
        }
        if (strpos($flagName, 'Uni') !== false) {
            return '⚲ Unisex tričko';
        }

        return '⚪ Ostatní';
    }

    public function getFlagPriceGroup(): string
    {
        $price = $this->getPrice();
        if ($price > 0) {
            return '⊕ S příplatkem';
        }
        if ($price < 0) {
            return '⊖ Se slevou';
        }

        return '⊜ Bez příplatku';
    }

    public function getName(): ?string
    {
        return $this->traitGetName() ?? ($this->getFlag() ? $this->getFlag()->getName() : null);
    }

    public function getShortName(): ?string
    {
        return $this->traitGetShortName() ?? ($this->getFlag() ? $this->getFlag()->getShortName() : null);
    }
}
