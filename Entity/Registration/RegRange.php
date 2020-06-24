<?php
/**
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
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * Time range available for registrations of participants of some type to some event (with some price, capacity...).
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\RegRangeRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_reg_range")
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
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\RegRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegRange $requiredRegRange;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantCategory $participantCategory = null;

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
     * @throws NotImplementedException
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
        $this->setParticipantCategory($participantType);
        $this->setEventPrice($eventPrice);
        $this->setStartDateTime($dateTimeRange ? $dateTimeRange->startDateTime : null);
        $this->setEndDateTime($dateTimeRange ? $dateTimeRange->endDateTime : null, true);
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
        return (!$this->isRelative() || null === $this->getRequiredRegRange()) ? 0 : $this->getRequiredRegRange()->getPrice($participantType);
    }

    public function isRelative(): bool
    {
        return $this->relative ?? false;
    }

    public function getRequiredRegRange(): ?RegRange
    {
        return $this->requiredRegRange;
    }

    public function setRequiredRegRange(?RegRange $requiredRegRange): void
    {
        $this->requiredRegRange = $requiredRegRange;
    }

    public function getPrice(?ParticipantCategory $participantType = null): int
    {
        if (null === $participantType) {
            return $this->traitGetPrice();
        }
        $price = $this->getParticipantCategory() === $participantType ? $this->traitGetPrice() : 0;
        $price += $this->getRequiredRangePrice($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
    }

    public function getParticipantCategory(): ?ParticipantCategory
    {
        return $this->participantCategory;
    }

    /**
     * @param ParticipantCategory|null $participantCategory
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
        if (false === $recursive || null === $participantType) {
            return $this->traitGetDeposit();
        }
        $price = $this->getParticipantCategory() === $participantType ? $this->traitGetDeposit() : 0;
        $price += $this->getRequiredRangeDeposit($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
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

    public function addFlagGroupRange(?FlagGroupRange $flagGroupRange): void
    {
        if (null !== $flagGroupRange && !$this->getFlagGroupRanges()->contains($flagGroupRange)) {
            $this->getFlagGroupRanges()->add($flagGroupRange);
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
            $flagCategoryRanges = $flagCategoryRanges->filter(fn(FlagGroupRange $range) => $range->isType($flagType));
        }

        return $flagCategoryRanges;
    }

    /**
     * @param FlagGroupRange|null $flagGroupRange
     *
     * @throws NotImplementedException
     */
    public function removeFlagGroupRange(?FlagGroupRange $flagGroupRange): void
    {
        if (null === $flagGroupRange || !$this->getFlagGroupRanges()->contains($flagGroupRange)) {
            return;
        }
        throw new NotImplementedException('Nelze odebrat již využitý rozsah.');
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
     * @throws NotImplementedException
     */
    public function setEvent(?Event $event): void
    {
        if (null === $this->event || $this->event === $event) {
            $this->event = $event;

            return;
        }
        throw new NotImplementedException('změna události', 'v rozsahu registrací');
    }
}
