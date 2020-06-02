<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\EventCapacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\EventPrice;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagsAggregatedByType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Traits\Entity\EventCapacityTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\EventPriceTrait;
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
 */
class RegistrationsRange implements NameableInterface
{
    use NameableTrait;
    use EventPriceTrait {
        getPrice as protected traitGetPrice;
        getDepositValue as protected traitGetDeposit;
    }
    use EventCapacityTrait;
    use DateRangeTrait { // Range when registrations are allowed with this price.
        setEndDateTime as protected traitSetEnd;
    }
    use EntityPublicTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationsRange $requiredRange;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantType $participantType = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRange")
     * @Doctrine\ORM\Mapping\JoinTable(
     *      name="registrations_ranges_participant_flag_ranges",
     *      joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="registrations_range_id", referencedColumnName="id")},
     *      inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_flag_range_id", referencedColumnName="id")}
     * )
     */
    protected ?Collection $flagRanges = null;

    /**
     * Indicates that price is relative to required range.
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $relative = null;

    /**
     * @var int Number of usages of flag (must be updated from service!).
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=false)
     */
    protected int $usage = 0;

    /**
     * @var int Number of usages of flag, including overflow (must be updated from service!).
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=false)
     */
    protected int $fullUsage = 0;

    /**
     * Indicates that participation on super event is required.
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $superEventRequired = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null,
        ?ParticipantType $participantType = null,
        ?EventPrice $eventPrice = null,
        ?DateTimeRange $dateTimeRange = null,
        ?bool $isRelative = null,
        ?RegistrationsRange $requiredRange = null,
        ?EventCapacity $eventCapacity = null,
        Publicity $publicity = null,
        ?bool $superEventRequired = null
    ) {
        $this->flagRanges = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        try {
            $this->setEvent($event);
            $this->setParticipantType($participantType);
        } catch (OswisNotImplementedException $e) {
            // Exception is thrown only when changing event, not when setting event in object constructor.
        }
        $this->setEventPrice($eventPrice);
        $this->setStartDateTime($dateTimeRange ? $dateTimeRange->startDateTime : null);
        $this->setEndDateTime($dateTimeRange ? $dateTimeRange->endDateTime : null, true);
        $this->setEventCapacity($eventCapacity);
        $this->setRelative($isRelative);
        $this->setRequiredRange($requiredRange);
        $this->setFieldsFromPublicity($publicity);
        $this->setSuperEventRequired($superEventRequired);
    }

    public function getUsage(bool $max = false): int
    {
        return $max ? $this->getFullUsage() : $this->usage;
    }

    public function setUsage(int $usage): void
    {
        $this->usage = $usage;
    }

    public function getFullUsage(): int
    {
        return $this->fullUsage;
    }

    public function setFullUsage(int $fulUsage): void
    {
        $this->fullUsage = $fulUsage;
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
        if ($this->event === $event) {
            return;
        }
        if (null === $this->event) {
            $this->event = $event;
        }
        throw new OswisNotImplementedException('změna události', 'v rozsahu registrací');
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
                // TODO: Probably better to test this in subscriber.
            }
        } catch (Exception $e) {
        }
    }

    public function getRemainingCapacity(bool $max = false): ?int
    {
        $capacity = $this->getCapacity($max);

        return null === $capacity ? null : ($capacity - $this->getUsage($max));
    }

    public function getRequiredRangePrice(?ParticipantType $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRange()) ? 0 : $this->getRequiredRange()->getPrice($participantType);
    }

    public function getRequiredRangeDeposit(?ParticipantType $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRange()) ? 0 : $this->getRequiredRange()->getDepositValue($participantType);
    }

    public function getPrice(?ParticipantType $participantType): int
    {
        $price = $this->getParticipantType() === $participantType ? $this->traitGetPrice() : 0;
        $price += $this->getRequiredRangePrice($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
    }

    public function getDepositValue(?ParticipantType $participantType): int
    {
        $price = $this->getParticipantType() === $participantType ? $this->traitGetDeposit() : 0;
        $price += $this->getRequiredRangeDeposit($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
    }

    public function isApplicableByType(?ParticipantType $participantType = null, ?DateTime $dateTime = null): bool
    {
        if (null !== $participantType && null === $this->getParticipantType()) {
            return false;
        }
        if (null !== $participantType && $this->getParticipantType()->getId() !== $participantType->getId()) {
            return false;
        }
        if (null !== $dateTime && !$this->containsDateTimeInRange($dateTime)) {
            return false;
        }

        return true;
    }

    /**
     * Checks capacity of range and flag ranges.
     *
     * @param array|null $flags
     * @param bool       $onlyPublic
     * @param bool|false $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateAdd(?array $flags = null, bool $onlyPublic = true, bool $max = false): void
    {
        $this->simulateParticipantAdd($onlyPublic, $max);
        $this->simulateFlagsAdd($flags, $onlyPublic, $max);
    }

    /**
     * Checks capacity of range.
     *
     * @param bool       $onlyPublic
     * @param bool|false $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateParticipantAdd(bool $onlyPublic = true, bool $max = false): void
    {
        $remainingCapacity = $this->getRemainingCapacity($max);
        if ((null !== $remainingCapacity && 1 > $remainingCapacity) || ($onlyPublic && !$this->isPublicOnWeb())) {
            throw new EventCapacityExceededException();
        }
    }

    /**
     * Checks capacity of flag ranges.
     *
     * @param array|null $flags
     * @param bool       $onlyPublic
     * @param bool|false $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateFlagsAdd(?array $flags = null, bool $onlyPublic = true, bool $max = false): void
    {
        foreach ($flags as $typeSlug => $flagsOfType) {
            foreach ($flagsOfType as $flagSlug => $aggregatedFlag) {
                $flagRemainingCapacity = $this->getFlagRemainingCapacity($aggregatedFlag['flag'] ?? null, $onlyPublic, $max);
                if (($aggregatedFlag['count'] ?? 0) > $flagRemainingCapacity) {
                    $flagName = $aggregatedFlag['flag'] ? '"'.$aggregatedFlag['flag'].'"' : '';
                    throw new EventCapacityExceededException("Kapacita příznaku $flagName byla vyčerpána.");
                }
            }
        }
    }

    public function getParticipantType(): ?ParticipantType
    {
        return $this->participantType;
    }

    /**
     * @param ParticipantType|null $participantType
     *
     * @throws OswisNotImplementedException
     */
    public function setParticipantType(?ParticipantType $participantType): void
    {
        if ($this->participantType === $participantType) {
            return;
        }
        if (null === $this->participantType) {
            $this->participantType = $participantType;
        }
        throw new OswisNotImplementedException('změna typu účastníka', 'v rozsahu registrací');
    }

    public function isApplicableByTypeOfType(?string $participantType = null, ?DateTime $dateTime = null): bool
    {
        if (null !== $participantType && (null === $this->getParticipantType() || $this->getParticipantType()->getType() !== $participantType)) {
            return false;
        }

        return null === $dateTime || $this->containsDateTimeInRange($dateTime);
    }

    public function isRelative(): bool
    {
        return $this->relative ?? false;
    }

    public function setRelative(?bool $relative): void
    {
        $this->relative = $relative;
    }

    public function isSuperEventRequired(): bool
    {
        return $this->superEventRequired ?? false;
    }

    public function setSuperEventRequired(?bool $superEventRequired): void
    {
        $this->superEventRequired = $superEventRequired;
    }

    public function getRequiredRange(): ?RegistrationsRange
    {
        return $this->requiredRange;
    }

    public function setRequiredRange(?RegistrationsRange $requiredRange): void
    {
        $this->requiredRange = $requiredRange;
    }

    public function isRangeActive(bool $max = false): bool
    {
        return $this->containsDateTimeInRange() && (null === $this->getCapacity($max) || 0 < $this->getCapacity($max));
    }

    public function getFlagRanges(?ParticipantFlagType $flagType = null, ?ParticipantFlag $flag = null, ?bool $onlyPublic = false): Collection
    {
        $flagRanges = $this->flagRanges ?? new ArrayCollection();
        if (null !== $flag) {
            $flagRanges = $flagRanges->filter(fn(ParticipantFlagRange $range) => $range->containsFlag($flag));
        }
        if (true === $onlyPublic) {
            $flagRanges = $flagRanges->filter(fn(ParticipantFlagRange $range) => $range->isPublicOnWeb());
        }
        if (null !== $flagType) {
            $flagRanges = $flagRanges->filter(fn(ParticipantFlagRange $range) => $range->containsFlagOfType($flagType));
        }

        return $flagRanges;
    }

    public function getAllowedFlagRangesByTypeString(?string $flagType = null, ?ParticipantFlag $flag = null, ?bool $onlyPublic = false): Collection
    {
        return $this->getFlagRanges(null, $flag, $onlyPublic)->filter(
            fn(ParticipantFlagRange $range) => $range->containsFlagOfTypeString($flagType)
        );
    }

    public function addFlagRange(?ParticipantFlagRange $flagRange): void
    {
        if (null !== $flagRange && !$this->getFlagRanges()->contains($flagRange)) {
            $this->getFlagRanges()->add($flagRange);
        }
    }

    public function getFlagRange(ParticipantFlag $flag, bool $max = false, bool $onlyPublic = false): ?ParticipantFlagRange
    {
        foreach ($this->getFlagRanges(null, $flag, $onlyPublic) as $range) {
            if ($range instanceof ParticipantFlagRange && $range->hasRemainingCapacity($max)) {
                return $range;
            }
        }

        return null;
    }

    /**
     * @param ParticipantFlagRange|null $flagRange
     *
     * @throws OswisNotImplementedException
     */
    public function removeFlagRange(?ParticipantFlagRange $flagRange): void
    {
        if (null === $flagRange) {
            return;
        }
        if ($flagRange->getUsage() > 0) {
            throw new OswisNotImplementedException('Nelze odebrat již využitý rozsah.');
        }
        $this->getFlagRanges()->remove($flagRange);
    }

    public function getFlagRemainingCapacity(ParticipantFlag $flag, bool $onlyPublic = false, bool $max = false): ?int
    {
        $remaining = null;
        foreach ($this->getFlagRanges(null, $flag, $onlyPublic) as $flagRange) {
            if (!($flagRange instanceof ParticipantFlagRange)) {
                continue;
            }
            $remainingInRange = $flagRange->getRemainingCapacity($max);
            if (null !== $remainingInRange) {
                $remaining += $remainingInRange;
            }
        }

        return $remaining;
    }

    public function isParticipantInSuperEvent(Participant $participant): bool
    {
        return $this->getEvent() && $this->getEvent()->isEventSuperEvent($participant->getEvent());
    }

    public function getFlags(
        ?ParticipantFlagType $flagType = null,
        ?ParticipantFlag $flag = null,
        bool $onlyPublic = true,
        bool $max = false
    ): Collection {
        $out = new ArrayCollection();
        foreach ($this->getFlagRanges($flagType, $flag, $onlyPublic) as $flagRange) {
            if ($flagRange instanceof ParticipantFlagRange) {
                for ($i = 0; $i < $flagRange->getCapacity($max); $i++) {
                    $out->add($flagRange->getFlag());
                }
            }
        }

        return $out;
    }

    /**
     * Gets array of flags aggregated by their types.
     *
     * @param bool $onlyPublic
     * @param bool $max
     *
     * @return array
     */
    public function getFlagsAggregatedByType(bool $onlyPublic = true, bool $max = false): array
    {
        return FlagsAggregatedByType::getFlagsAggregatedByType($this->getFlags(null, null, $onlyPublic, $max));
    }
}
