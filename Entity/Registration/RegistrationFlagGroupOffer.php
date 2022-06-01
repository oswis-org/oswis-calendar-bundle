<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
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
 */
#[Entity]
#[Table(name : 'calendar_flag_group_range')]
#[Cache(usage : 'NONSTRICT_READ_WRITE', region : 'calendar_flag_range')]
class RegistrationFlagGroupOffer implements NameableInterface
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

    #[Column(type : 'string', nullable : true)]
    protected ?string $emptyPlaceholder = null;

    #[ManyToOne(targetEntity : RegistrationFlagCategory::class, fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?RegistrationFlagCategory $flagCategory = null;

    /**
     * @var Collection<RegistrationFlagOffer>
     */
    #[ManyToMany(targetEntity : RegistrationFlagOffer::class, cascade : ['all'], fetch : 'EAGER')]
    #[JoinTable(name : 'calendar_flag_group_range_flag_connection')]
    #[JoinColumn(name : "flag_group_range_id", referencedColumnName : "id")]
    #[InverseJoinColumn(name : "flag_range_id", referencedColumnName : "id", unique : true)]
    protected Collection $flagOffers;

    public function __construct(
        ?RegistrationFlagCategory $category = null,
        ?FlagAmountRange $flagAmountRange = null,
        ?Publicity $publicity = null,
        ?string $emptyPlaceholder = null
    ) {
        $this->flagOffers = new ArrayCollection();
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

    public function addFlagRange(?RegistrationFlagOffer $flagRange): void
    {
        if (null !== $flagRange && !$this->getFlagOffers()->contains($flagRange)) {
            $this->getFlagOffers()->add($flagRange);
        }
    }

    /**
     * @param  bool  $onlyPublic
     * @param  RegistrationFlag|null  $flag
     *
     * @return Collection<RegistrationFlagOffer>
     */
    public function getFlagOffers(bool $onlyPublic = false, RegistrationFlag $flag = null): Collection
    {
        $flagRanges = $this->flagOffers;
        if (true === $onlyPublic) {
            $flagRanges = $flagRanges->filter(fn(mixed $flagRange) => $flagRange instanceof RegistrationFlagOffer && $flagRange->isPublicOnWeb(),);
        }
        if (null !== $flag) {
            $flagRanges = $flagRanges->filter(fn(mixed $flagRange) => $flagRange instanceof RegistrationFlagOffer && $flagRange->getFlag() === $flag,);
        }

        /** @var Collection<RegistrationFlagOffer> $flagRanges */
        return $flagRanges;
    }

    /**
     * @param  RegistrationFlagOffer|null  $flagRange
     *
     * @throws NotImplementedException
     */
    public function removeFlagRange(?RegistrationFlagOffer $flagRange): void
    {
        if (null !== $flagRange) {
            throw new NotImplementedException('odebrání rozsahu příznaku ze skupiny', 'u rozsahu příznaků');
        }
    }

    public function isCategory(?RegistrationFlagCategory $category = null): bool
    {
        return null === $category || ($this->getFlagCategory() && $this->getFlagCategory() === $category);
    }

    public function getFlagCategory(): ?RegistrationFlagCategory
    {
        return $this->flagCategory;
    }

    public function setFlagCategory(?RegistrationFlagCategory $flagCategory): void
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

    public function isFlagValueAllowed(bool $onlyPublic = false, ?RegistrationFlag $flag = null): bool
    {
        return $this->getFlagOffers($onlyPublic, $flag)->filter(fn(mixed $flagRange) => $flagRange instanceof RegistrationFlagOffer
                                                                                        && $flagRange->isFormValueAllowed(),)->count() > 0;
    }

    public function hasFlagValueAllowed(): bool
    {
        return $this->getFlagOffers()
                    ->filter(static fn(mixed $flagRange) => $flagRange instanceof RegistrationFlagOffer && $flagRange->isFormValueAllowed(),)
                    ->count() > 0;
    }

    public function getFlagsGroupNames(): array
    {
        $groups = [];
        foreach ($this->getFlagOffers(true) as $flagRange) {
            $groupName          = $flagRange->getFlagGroupName();
            $groups[$groupName] = ($groups[$groupName] ?? 0) + 1;
        }

        return $groups;
    }

    public function getFlagGroupName(): ?string
    {
        return $this->getName() ?? $this->getFlagCategory()?->getName();
    }

    public function getName(): ?string
    {
        return $this->traitGetName() ?? $this->getFlagCategory()?->getName();
    }

    public function getShortName(): ?string
    {
        return $this->traitGetShortName() ?? $this->getFlagCategory()?->getShortName();
    }

    public function getDescription(): string
    {
        $description = $this->traitGetDescription();

        return empty($description) ? ($this->getFlagCategory()?->getDescription() ?? '') : $description;
    }

    public function getNote(): string
    {
        return $this->traitGetNote() ?? $this->getFlagCategory()?->getNote() ?? '';
    }
}
