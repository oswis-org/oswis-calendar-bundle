<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use DateTime;
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
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationOfferRepository;
use OswisOrg\OswisCalendarBundle\Traits\Entity\CapacityTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\CapacityUsageTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\PriceTrait;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;

/**
 * Time range available for registrations of participants of some type to some event (with some price, capacity...).
 * @todo Implement: Check capacity of required "super" ranges (add somehow participant to them?).
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_reg_ranges_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_reg_ranges_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_reg_range_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_reg_range_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 */
#[ApiFilter(SearchFilter::class, strategy : 'exact', properties : ['event.id', 'event.superEvent.id'])]
#[Entity(repositoryClass : RegistrationOfferRepository::class)]
#[Table(name : 'calendar_reg_range')]
#[Cache(usage : 'NONSTRICT_READ_WRITE', region : 'calendar_reg_range')]
class RegistrationOffer implements NameableInterface
{
    use NameableTrait;
    use PriceTrait {
        getPrice as protected traitGetPrice;
        getDepositValue as protected traitGetDeposit;
    }
    use CapacityTrait;
    use CapacityUsageTrait;
    use DateRangeTrait { // Range when registrations are allowed with this price.
        setEndDateTime as protected traitSetEnd;
    }
    use EntityPublicTrait;
    use PriorityTrait;

    #[ManyToOne(targetEntity : RegistrationOffer::class, fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?RegistrationOffer $requiredRegRange;

    #[ManyToOne(targetEntity : Event::class, fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?Event $event = null;

    #[ManyToOne(targetEntity : ParticipantCategory::class, fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?ParticipantCategory $participantCategory = null;

    /**
     * @var Collection<RegistrationFlagGroupOffer> $flagGroupRanges
     */
    #[ManyToMany(targetEntity : RegistrationFlagGroupOffer::class, cascade : ['all'])]
    #[JoinTable(name : 'calendar_reg_range_flag_group_range')]
    #[JoinColumn(name : "reg_range_id", referencedColumnName : "id")]
    #[InverseJoinColumn(name : "flag_group_range_id", referencedColumnName : "id", unique : true)]
    protected Collection $flagGroupRanges;

    /**
     * Indicates that price is relative to required range.
     * @todo Implement: Indicates that capacity is relative to required range too.
     */
    #[Column(type : 'boolean', nullable : true)]
    protected ?bool $relative = null;

    #[Column(type : 'boolean', nullable : false)]
    protected bool $surrogate = false;

    /**
     * Indicates that participation on super event is required.
     */
    #[Column(type : 'boolean', nullable : true)]
    protected ?bool $superEventRequired = null;

    /**
     * RegistrationsRange constructor.
     *
     * @param  Nameable|null  $nameable
     * @param  Event|null  $event
     * @param  ParticipantCategory|null  $participantType
     * @param  Price|null  $eventPrice
     * @param  DateTimeRange|null  $dateTimeRange
     * @param  bool|null  $isRelative
     * @param  RegistrationOffer|null  $requiredRange
     * @param  Capacity|null  $eventCapacity
     * @param  Publicity|null  $publicity
     * @param  bool|null  $superEventRequired
     *
     * @throws NotImplementedException
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null,
        ?ParticipantCategory $participantType = null,
        ?Price $eventPrice = null,
        ?DateTimeRange $dateTimeRange = null,
        ?bool $isRelative = null,
        ?RegistrationOffer $requiredRange = null,
        ?Capacity $eventCapacity = null,
        Publicity $publicity = null,
        ?bool $superEventRequired = null
    ) {
        $this->flagGroupRanges = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setEvent($event);
        $this->setParticipantCategory($participantType);
        $this->setEventPrice($eventPrice);
        $this->setStartDateTime($dateTimeRange?->startDateTime);
        $this->setEndDateTime($dateTimeRange?->endDateTime, true);
        $this->setCapacity($eventCapacity);
        $this->setRelative($isRelative);
        $this->setRequiredRegRange($requiredRange);
        $this->setFieldsFromPublicity($publicity);
        $this->setSuperEventRequired($superEventRequired);
    }

    public function setEndDateTime(?DateTime $endDateTime, ?bool $force = null): void
    {
        try {
            // Sets the end of registration range (can't be set to past).
            $now = new DateTime();
            if ($endDateTime !== $this->getEndDate()) {
                $this->traitSetEnd(!$force && $endDateTime && $endDateTime < $now ? $now : $endDateTime);
            }
        } catch (Exception) {
        }
    }

    public function setRelative(?bool $relative): void
    {
        $this->relative = $relative;
    }

    public function setSuperEventRequired(?bool $superEventRequired): void
    {
        $this->superEventRequired = $superEventRequired;
    }

    public function isSurrogate(): bool
    {
        return $this->surrogate;
    }

    public function setSurrogate(bool $surrogate): void
    {
        $this->surrogate = $surrogate;
    }

    public function getRequiredRangePrice(?ParticipantCategory $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRegRange()) ? 0 : $this->getRequiredRegRange()->getPrice($participantType);
    }

    public function isRelative(): bool
    {
        return $this->relative ?? false;
    }

    public function getRequiredRegRange(): ?RegistrationOffer
    {
        return $this->requiredRegRange;
    }

    public function setRequiredRegRange(?RegistrationOffer $requiredRegRange): void
    {
        $this->requiredRegRange = $requiredRegRange;
    }

    public function getPrice(?ParticipantCategory $participantType = null, bool $recursive = true): int
    {
        $price = null === $participantType || $this->getParticipantCategory() === $participantType ? $this->traitGetPrice() : 0;
        if ($recursive) {
            $price += $this->getRequiredRangePrice($participantType);
        }

        return max($price ?? 0, 0);
    }

    public function getParticipantCategory(): ?ParticipantCategory
    {
        return $this->participantCategory;
    }

    /**
     * @param  ParticipantCategory|null  $participantCategory
     *
     * @throws NotImplementedException
     */
    public function setParticipantCategory(?ParticipantCategory $participantCategory): void
    {
        if (null === $this->participantCategory || $this->participantCategory === $participantCategory) {
            $this->participantCategory = $participantCategory;

            return;
        }
        throw new NotImplementedException('změna typu účastníka', 'v rozsahu registrací');
    }

    public function getRequiredRangeDeposit(?ParticipantCategory $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRegRange()) ? 0 : $this->getRequiredRegRange()->getDepositValue($participantType);
    }

    public function getDepositValue(?ParticipantCategory $participantType = null, bool $recursive = true): int
    {
        $price = null === $participantType || $this->getParticipantCategory() === $participantType ? $this->traitGetDeposit() : 0;
        if ($recursive) {
            $price += $this->getRequiredRangeDeposit($participantType);
        }

        return null !== $price && $price <= 0 ? 0 : $price;
    }

    public function getRestValue(?ParticipantCategory $participantType = null, bool $recursive = true): int
    {
        return $this->getPrice($participantType, $recursive) - $this->getDepositValue($participantType, $recursive);
    }

    /**
     * Participation in super event is required before registration to this range. Must be checked in service/controller.
     * @return bool
     * @todo Use it and check it!
     */
    public function isSuperEventRequired(): bool
    {
        return $this->superEventRequired ?? false;
    }

    public function isRangeActive(bool $max = false): bool
    {
        $capacity = $this->getCapacityInt($max);

        return $this->isInDateRange() && (null === $capacity || 0 < $capacity);
    }

    public function addFlagGroupRange(?RegistrationFlagGroupOffer $flagGroupRange): void
    {
        if (null !== $flagGroupRange && !$this->getFlagGroupRanges()->contains($flagGroupRange)) {
            $this->getFlagGroupRanges()->add($flagGroupRange);
        }
    }

    public function getFlagGroupRanges(
        ?RegistrationFlagCategory $flagCategory = null,
        ?string $flagType = null,
        ?bool $onlyPublic = false,
        bool $recursive = false
    ): Collection {
        $flagGroupRanges = $this->flagGroupRanges;
        if (true === $onlyPublic) {
            $flagGroupRanges = $flagGroupRanges->filter(fn(mixed $range) => $range instanceof RegistrationFlagGroupOffer && $range->isPublicOnWeb());
        }
        if (null !== $flagCategory) {
            $flagGroupRanges = $flagGroupRanges->filter(fn(mixed $range) => $range instanceof RegistrationFlagGroupOffer && $range->isCategory($flagCategory));
        }
        if (null !== $flagType) {
            $flagGroupRanges = $flagGroupRanges->filter(fn(mixed $range) => $range instanceof RegistrationFlagGroupOffer && $range->isType($flagType));
        }
        if (true === $recursive && null !== $this->getRequiredRegRange()) {
            $flagGroupRanges = new ArrayCollection([
                ...$flagGroupRanges,
                ...$this->getRequiredRegRange()->getFlagGroupRanges(),
            ]);
        }

        return $flagGroupRanges;
    }

    /**
     * @param  RegistrationFlagGroupOffer|null  $flagGroupRange
     *
     * @throws NotImplementedException
     */
    public function removeFlagGroupRange(?RegistrationFlagGroupOffer $flagGroupRange): void
    {
        if (null === $flagGroupRange || !$this->getFlagGroupRanges()->contains($flagGroupRange)) {
            return;
        }
        throw new NotImplementedException('Nelze odebrat již využitý rozsah.');
    }

    public function isParticipantInSuperEvent(?Participant $participant = null): bool
    {
        return $participant instanceof Participant && $this->getEvent() && $this->getEvent()->isEventSuperEvent($participant->getEvent());
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * @param  Event|null  $event
     *
     * @throws NotImplementedException
     * @TODO Catch change of event in subscribers and resend participant summaries!
     */
    public function setEvent(?Event $event): void
    {
        if (null !== $this->event && null === $event) {
            throw new NotImplementedException("odebrání události", "u rozsahů přihlášek");
        }
        $this->event = $event;
    }

    /**
     * @param  ParticipantFlagGroup  $oldParticipantFlagGroup
     * @param  bool  $admin
     * @param  bool  $onlySimulate
     *
     * @return ParticipantFlagGroup
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException
     */
    public function makeCompatibleParticipantFlagGroup(
        ParticipantFlagGroup $oldParticipantFlagGroup,
        bool $admin = false,
        bool $onlySimulate = false,
    ): ParticipantFlagGroup {
        if (null === ($oldFlagGroupRange = $oldParticipantFlagGroup->getFlagGroupOffer())) {
            throw new FlagOutOfRangeException('Neplatný rozsah příznaků.');
        }
        if ($this->isFlagGroupRangeCompatible($oldFlagGroupRange)) {
            return $oldParticipantFlagGroup;
        }
        $newParticipantFlagGroup = new ParticipantFlagGroup($this->findCompatibleFlagGroupRange($oldFlagGroupRange));
        foreach ($oldParticipantFlagGroup->getParticipantFlags(true) as $oldParticipantFlag) {
            if (!($oldParticipantFlag instanceof ParticipantFlag)
                || ($newParticipantFlagGroup->getAvailableFlagOffers()->contains($oldParticipantFlag->getFlagOffer()))) {
                continue;
            }
            $newParticipantFlag = $this->makeCompatibleParticipantFlag($oldParticipantFlag, $onlySimulate);
            $newFlagRange       = $newParticipantFlag->getFlagOffer();
            if (null !== $newFlagRange && $oldParticipantFlag->getFlagOffer() !== $newFlagRange) {
                $remainingFlagRangeCapacity = $newFlagRange->getRemainingCapacity($admin);
                if (null !== $remainingFlagRangeCapacity && (0 === $remainingFlagRangeCapacity || -1 >= $remainingFlagRangeCapacity)) {
                    throw new FlagCapacityExceededException($newFlagRange->getName());
                }
            }
            if (!$onlySimulate) {
                $newParticipantFlagGroup->replaceParticipantFlag($oldParticipantFlag, $newParticipantFlag);
            }
        }

        return $newParticipantFlagGroup;
    }

    public function isFlagGroupRangeCompatible(?RegistrationFlagGroupOffer $flagGroupRange = null): bool
    {
        return $this->getFlagGroupRanges(null, null, false, true)->contains($flagGroupRange);
    }

    /**
     * @param  RegistrationFlagGroupOffer  $flagGroupRange
     *
     * @return RegistrationFlagGroupOffer
     * @throws FlagOutOfRangeException
     */
    public function findCompatibleFlagGroupRange(RegistrationFlagGroupOffer $flagGroupRange): RegistrationFlagGroupOffer
    {
        if ($this->isFlagGroupRangeCompatible($flagGroupRange)) {
            return $flagGroupRange;
        }
        $flagCategory = $flagGroupRange->getFlagCategory();
        foreach ($this->getFlagGroupRanges(null, null, false, true) as $newFlagGroupRange) {
            if ($newFlagGroupRange instanceof RegistrationFlagGroupOffer && $newFlagGroupRange->getFlagCategory() === $flagCategory) {
                return $newFlagGroupRange;
            }
        }
        $flagGroupName = $flagGroupRange->getName();
        $regRangeName  = $this->getName();
        throw new FlagOutOfRangeException("Skupinu příznaků '$flagGroupName' není možné v rozsahu přihlášek '$regRangeName' použít (neexistuje automatická náhrada).");
    }

    /**
     * @param  ParticipantFlag  $oldParticipantFlag
     * @param  bool  $onlySimulate
     *
     * @return ParticipantFlag
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException
     */
    public function makeCompatibleParticipantFlag(ParticipantFlag $oldParticipantFlag, bool $onlySimulate = false): ParticipantFlag
    {
        if (null === $oldParticipantFlag->getFlag() || null === ($oldFlagRange = $oldParticipantFlag->getFlagOffer())) {
            throw new FlagOutOfRangeException('Prázdné přiřazení příznaku.');
        }
        if ($this->isFlagRangeCompatible($oldFlagRange)) {
            return $oldParticipantFlag;
        }
        $newParticipantFlag = new ParticipantFlag($this->findCompatibleFlagRange($oldFlagRange), null, $oldParticipantFlag->getTextValue());
        if (!$onlySimulate) {
            $oldParticipantFlag->delete();
        }

        return $newParticipantFlag;
    }

    public function isFlagRangeCompatible(?RegistrationFlagOffer $flagRange = null): bool
    {
        foreach ($this->getFlagGroupRanges(null, null, false, true) as $flagGroupRange) {
            if ($flagGroupRange instanceof RegistrationFlagGroupOffer && $flagGroupRange->getFlagOffers()->contains($flagRange)) {
                return true;
            }
        }

        return false;
    }

    public function findCompatibleFlagRange(RegistrationFlagOffer $oldFlagRange): ?RegistrationFlagOffer
    {
        if ($this->isFlagRangeCompatible($oldFlagRange)) {
            return $oldFlagRange;
        }
        foreach ($this->getFlagGroupRanges(null, null, false, true) as $flagGroupRange) {
            if ($flagGroupRange instanceof RegistrationFlagGroupOffer) {
                foreach ($flagGroupRange->getFlagOffers() as $oneFlagRange) {
                    if ($oneFlagRange instanceof RegistrationFlagOffer && $oneFlagRange->getFlag() === $oldFlagRange->getFlag()) {
                        return $oneFlagRange;
                    }
                }
            }
        }

        return null;
    }

    public function getRemainingCapacity(bool $full = false): ?int
    {
        $capacity = $this->getCapacityInt($full);

        return null === $capacity ? null : ($capacity - $this->getUsageInt($full));
    }
}
