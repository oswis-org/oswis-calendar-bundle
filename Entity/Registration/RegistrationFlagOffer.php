<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
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
 */
#[ApiResource(
    security: 'is_granted("ROLE_MANAGER")',
    filters: ['search'],
    operations: [
        new GetCollection(
            security: 'is_granted("ROLE_MANAGER")',
            normalizationContext: [
                'groups' => ['entities_get', 'calendar_flag_ranges_get'],
                'enable_max_depth' => true,
            ]
        ),
        new Post(
            security: 'is_granted("ROLE_MANAGER")',
            denormalizationContext: [
                'groups' => ['entities_post', 'calendar_flag_ranges_post'],
                'enable_max_depth' => true,
            ]
        ),
        new Get(
            security: 'is_granted("ROLE_MANAGER")',
            normalizationContext: [
                'groups' => ['entity_get', 'calendar_flag_range_get'],
                'enable_max_depth' => true,
            ]
        ),
        new Put(
            security: 'is_granted("ROLE_MANAGER")',
            denormalizationContext: [
                'groups' => ['entity_put', 'calendar_flag_range_put'],
                'enable_max_depth' => true,
            ]
        ),
    ]
)]
#[Entity]
#[Table(name: 'calendar_flag_range')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_flag_range')]
class RegistrationFlagOffer implements NameableInterface
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

    #[ManyToOne(targetEntity: RegistrationFlag::class, fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    protected ?RegistrationFlag $flag = null;

    #[Column(type: 'string', nullable: true)]
    protected ?string $flagFormGroup = null;

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

    public function getDepositValue(): int
    {
        return $this->depositValue ?? 0;
    }

    public function isFlag(?RegistrationFlag $flag = null): bool
    {
        return null === $flag || ($this->getFlag() && $this->getFlag() === $flag);
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
        return null === $category || $this->getCategory() === $category;
    }

    public function getCategory(): ?RegistrationFlagCategory
    {
        return $this->getFlag()?->getCategory();
    }

    public function isType(?string $flagType = null): bool
    {
        return null === $flagType || $this->getType() === $flagType;
    }

    public function getType(): ?string
    {
        return $this->getFlag()?->getType();
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
        return $this->traitGetName() ?? $this->getFlag()?->getName();
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
        $flag = $this->getFlag();
        if (null !== $flag?->getFlagFormGroup()) {
            /** @noinspection NullPointerExceptionInspection */
            return $flag->getFlagFormGroup();
        }
        if (RegistrationFlagCategory::TYPE_T_SHIRT_SIZE === $this->getType()) {
            return $this->getTShirtGroup();
        }
        if (RegistrationFlagCategory::TYPE_SCHOOL === $this->getType()) {
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
        if (str_contains(''.$flagName, 'Pán')) {
            return '♂ Pánské tričko';
        }
        if (str_contains(''.$flagName, 'Dám')) {
            return '♀ Dámské tričko';
        }
        if (str_contains(''.$flagName, 'Uni')) {
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
        return $this->shortName ?? $this->getFlag()?->getShortName();
    }

    public function getDescription(): string
    {
        $description = $this->traitGetDescription();

        return empty($description) ? ($this->getFlag()?->getDescription() ?? '') : $description;
    }

    public function getNote(): string
    {
        return $this->traitGetNote() ?? $this->getFlag()?->getNote() ?? '';
    }
}
