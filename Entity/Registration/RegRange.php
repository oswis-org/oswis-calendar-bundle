<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Traits\Entity\CapacityTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\CapacityUsageTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\PriceTrait;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * Time range available for registrations of participants of some type to some event (with some price, capacity...).
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\RegistrationsRangeRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_registrations_range")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 * @todo Implement: Check capacity of required "super" ranges (add somehow participant to them?).
 */
class RegRange implements NameableInterface
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

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="RegRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegRange $requiredRange;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="ParticipantCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantCategory $participantType = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\FlagGroupRange", cascade={"all"})
     * @Doctrine\ORM\Mapping\JoinTable(
     *      name="calendar_reg_range_flag_group_range",
     *      joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="reg_range_id", referencedColumnName="id")},
     *      inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="flag_group_range_id", referencedColumnName="id")}
     * )
     */
    protected ?Collection $flagGroupRanges = null;

    /**
     * Indicates that price is relative to required range.
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     * @todo Implement: Indicates that capacity is relative to required range too.
     */
    protected ?bool $relative = null;

    /**
     * Indicates that participation on super event is required.
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $superEventRequired = null;

    /**
     * RegistrationsRange constructor.
     *
     * @param Nameable|null            $nameable
     * @param Event|null               $event
     * @param ParticipantCategory|null $participantType
     * @param Price|null               $eventPrice
     * @param DateTimeRange|null       $dateTimeRange
     * @param bool|null                $isRelative
     * @param RegRange|null            $requiredRange
     * @param Capacity|null            $eventCapacity
     * @param Publicity|null           $publicity
     * @param bool|null                $superEventRequired
     *
     * @throws OswisNotImplementedException
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null,
        ?ParticipantCategory $participantType = null,
        ?Price $eventPrice = null,
        ?DateTimeRange $dateTimeRange = null,
        ?bool $isRelative = null,
        ?RegRange $requiredRange = null,
        ?Capacity $eventCapacity = null,
        Publicity $publicity = null,
        ?bool $superEventRequired = null
    ) {
        $this->flagGroupRanges = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setEvent($event);
        $this->setParticipantType($participantType);
        $this->setEventPrice($eventPrice);
        $this->setStartDateTime($dateTimeRange ? $dateTimeRange->startDateTime : null);
        $this->setEndDateTime($dateTimeRange ? $dateTimeRange->endDateTime : null, true);
        $this->setCapacity($eventCapacity);
        $this->setRelative($isRelative);
        $this->setRequiredRange($requiredRange);
        $this->setFieldsFromPublicity($publicity);
        $this->setSuperEventRequired($superEventRequired);
    }

    /**
     * Sets the end of registration range (can't be set to past).
     *
     * @param DateTime|null $endDateTime
     * @param bool|null     $force
     */
    public function setEndDateTime(?DateTime $endDateTime, ?bool $force = null): void
    {
        try {
            $now = new DateTime();
            if ($endDateTime !== $this->getEndDate()) {
                $this->traitSetEnd(!$force && $endDateTime && $endDateTime < $now ? $now : $endDateTime);
            }
        } catch (Exception $e) {
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

    public function getRequiredRangePrice(?ParticipantCategory $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRange()) ? 0 : $this->getRequiredRange()->getPrice($participantType);
    }

    public function isRelative(): bool
    {
        return $this->relative ?? false;
    }

    public function getRequiredRange(): ?RegRange
    {
        return $this->requiredRange;
    }

    public function setRequiredRange(?RegRange $requiredRange): void
    {
        $this->requiredRange = $requiredRange;
    }

    public function getPrice(?ParticipantCategory $participantType = null): int
    {
        if (null === $participantType) {
            return $this->traitGetPrice();
        }
        $price = $this->getParticipantType() === $participantType ? $this->traitGetPrice() : 0;
        $price += $this->getRequiredRangePrice($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
    }

    public function getParticipantType(): ?ParticipantCategory
    {
        return $this->participantType;
    }

    /**
     * @param ParticipantCategory|null $participantType
     *
     * @throws OswisNotImplementedException
     */
    public function setParticipantType(?ParticipantCategory $participantType): void
    {
        if (null === $this->participantType || $this->participantType === $participantType) {
            $this->participantType = $participantType;

            return;
        }
        throw new OswisNotImplementedException('změna typu účastníka', 'v rozsahu registrací');
    }

    public function getRequiredRangeDeposit(?ParticipantCategory $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRange()) ? 0 : $this->getRequiredRange()->getDepositValue($participantType);
    }

    public function getDepositValue(?ParticipantCategory $participantType = null, bool $recursive = true): int
    {
        if (false === $recursive || null === $participantType) {
            return $this->traitGetDeposit();
        }
        $price = $this->getParticipantType() === $participantType ? $this->traitGetDeposit() : 0;
        $price += $this->getRequiredRangeDeposit($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
    }

    /**
     * Checks capacity of range and flag ranges.
     *
     * @param Participant $participant
     * @param bool        $onlyPublic
     * @param bool|false  $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateAdd(Participant $participant = null, bool $onlyPublic = true, bool $max = false): void
    {
        $this->simulateParticipantAdd($max);
        if (null !== $participant) {
            $this->simulateFlagsAdd($participant->getFlagsAggregatedByType(), $onlyPublic, $max);
        }
    }

    /**
     * Checks capacity of range.
     *
     * @param bool|false $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateParticipantAdd(bool $max = false): void
    {
        if (!$this->isInDateRange()) {
            $rangeText = $this->getRangeAsText();
            throw new EventCapacityExceededException("Přihlášky v tomto rozsahu aktuálně nejsou povoleny ($rangeText).");
        }
        $remainingCapacity = $this->getRemainingCapacity($max);
        if ((null !== $remainingCapacity && 1 > $remainingCapacity)) {
            throw new EventCapacityExceededException();
        }
    }

    public function getRemainingCapacity(bool $full = false): ?int
    {
        $capacity = $this->getCapacityInt($full);

        return null === $capacity ? null : ($capacity - $this->getUsageInt($full));
    }

    public function getBaseCapacity(bool $max = false): int
    {
        return $max ? $this->getFullCapacity() : $this->baseUsage;
    }

    public function getFullCapacity(): int
    {
        return $this->fullUsage;
    }

    public function setBaseCapacity(int $baseUsage): void
    {
        $this->baseUsage = $baseUsage;
    }

    public function setFullCapacity(int $fulUsage): void
    {
        $this->fullUsage = $fulUsage;
    }

    /**
     * Participation in super event is required before registration to this range.
     *
     * Must be checked in service/controller.
     *
     * @return bool
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

    public function addFlagRange(?RegistrationsFlagRange $flagRange): void
    {
        if (null !== $flagRange && !$this->getFlagGroupRanges()->contains($flagRange)) {
            $this->getFlagGroupRanges()->add($flagRange);
        }
    }

    public function getFlagGroupRanges(?FlagCategory $flagCategory = null, ?string $flagType = null, ?bool $onlyPublic = false): Collection
    {
        $flagCategoryRanges = $this->flagGroupRanges ??= new ArrayCollection();
        if (true === $onlyPublic) {
            $flagCategoryRanges = $flagCategoryRanges->filter(fn(FlagGroupRange $range) => $range->isPublicOnWeb());
        }
        if (null !== $flagCategory) {
            $flagCategoryRanges = $flagCategoryRanges->filter(fn(FlagGroupRange $range) => $range->isCategory($flagCategory));
        }
        if (null !== $flagType) {
            $flagCategoryRanges = $flagCategoryRanges->filter(fn(FlagGroupRange $range) => $range->isType($flagCategory));
        }

        return $flagCategoryRanges;
    }

    public function getFlagRange(Flag $flag, bool $max = false, bool $onlyPublic = false): ?FlagRange
    {
        foreach ($this->getFlagGroupRanges(null, $flag, $onlyPublic) as $range) {
            if ($range instanceof FlagRange && $range->getRemainingCapacity($max) !== 0) {
                return $range;
            }
        }

        return null;
    }

    public function removeFlagRange(?FlagRange $flagRange): void
    {
        if (null === $flagRange) {
            return;
        }
        if ($flagRange->getBaseUsage() > 0) {
            throw new OswisNotImplementedException('Nelze odebrat již využitý rozsah.');
        }
        $this->getFlagGroupRanges()->remove($flagRange);
    }

    public function isParticipantInSuperEvent(Participant $participant): bool
    {
        return $this->getEvent() && $this->getEvent()->isEventSuperEvent($participant->getEvent());
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * @param Event|null $event
     *
     * @throws OswisNotImplementedException
     */
    public function setEvent(?Event $event): void
    {
        if (null === $this->event || $this->event === $event) {
            $this->event = $event;

            return;
        }
        throw new OswisNotImplementedException('změna události', 'v rozsahu registrací');
    }
}
