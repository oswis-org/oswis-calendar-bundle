<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

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
 * Date range when flag can be used (with some capacity).
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_flag_range")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_flag_range")
 */
class FlagRange implements NameableInterface
{
    use NameableTrait {
        getName as traitGetName;
        getShortName as traitGetShortName;
        getDescription as traitGetDescription;
        getNote as traitGetNote;
    }
    use EntityPublicTrait;
    use CapacityTrait;
    use PriceTrait;
    use CapacityUsageTrait;
    use FlagAmountRangeTrait;
    use FormValueTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\Flag", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Flag $flag = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?string $flagFormGroup = null;

    public function __construct(
        ?Flag $flag = null,
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

    public function getDepositValue(): int
    {
        return $this->depositValue ?? 0;
    }

    public function isFlag(?Flag $flag = null): bool
    {
        return null === $flag ? true : $this->getFlag() && $this->getFlag() === $flag;
    }

    public function getFlag(): ?Flag
    {
        return $this->flag;
    }

    public function setFlag(?Flag $flag): void
    {
        $this->flag = $flag;
    }

    public function isCategory(?FlagCategory $category = null): bool
    {
        return null === $category ? true : $this->getCategory() && $this->getCategory() === $category;
    }

    public function getCategory(): ?FlagCategory
    {
        return $this->getFlag() ? $this->getFlag()->getCategory() : null;
    }

    public function isType(?string $flagType = null): bool
    {
        return null === $flagType ? true : $this->getType() === $flagType;
    }

    public function getType(): ?string
    {
        return $this->getFlag() ? $this->getFlag()->getType() : null;
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

    public function getName(): ?string
    {
        return $this->traitGetName() ?? ($this->getFlag() ? $this->getFlag()->getName() : null);
    }

    public function getPrice(): int
    {
        return $this->price ?? 0;
    }

    public function hasRemainingCapacity(bool $full = false): bool
    {
        return 0 !== $this->getRemainingCapacity($full);
    }

    public function getRemainingCapacity(bool $full = false): ?int
    {
        $capacity = $this->getCapacityInt($full);
        if (null === $capacity) {
            return null;
        }
        $remaining = $capacity - $this->getUsageInt($full);

        return $remaining < 1 ? 0 : $remaining;
    }

    public function getFlagGroupName(): ?string
    {
        if (null !== $this->getFlagFormGroup()) {
            return $this->getFlagFormGroup();
        }
        if ($this->getFlag() && null !== $this->getFlag()->getFlagFormGroup()) {
            return $this->getFlag()->getFlagFormGroup();
        }
        if (FlagCategory::TYPE_T_SHIRT_SIZE === $this->getType()) {
            return $this->getTShirtGroup();
        }
        if (FlagCategory::TYPE_SCHOOL === $this->getType()) {
            return null;
        }

        return $this->getFlagPriceGroup();
    }

    public function getFlagFormGroup(): ?string
    {
        return $this->flagFormGroup;
    }

    public function setFlagFormGroup(?string $flagFormGroup): void
    {
        $this->flagFormGroup = $flagFormGroup;
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

    public function getShortName(): ?string
    {
        return $this->traitGetShortName() ?? ($this->getFlag() ? $this->getFlag()->getShortName() : null);
    }

    public function getDescription(): string
    {
        return $this->traitGetDescription() ?? ($this->getFlag() ? $this->getFlag()->getDescription() : '');
    }

    public function getNote(): string
    {
        return $this->traitGetNote() ?? ($this->getFlag() ? $this->getFlag()->getNote() : '');
    }
}
