<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
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
 * @todo Implement: Check capacity of required "super" ranges (add somehow participant to them?).
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
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationsRange $requiredRange;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType", fetch="EAGER")
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
     * @todo Implement: Indicates that capacity is relative to required range too.
     */
    protected ?bool $relative = null;

    /**
     * @var int Number of usages of flag (must be updated from service!).
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=false)
     */
    protected int $baseUsage = 0;

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

    public function getRequiredRangePrice(?ParticipantType $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRange()) ? 0 : $this->getRequiredRange()->getPrice($participantType);
    }

    public function isRelative(): bool
    {
        return $this->relative ?? false;
    }

    public function getRequiredRange(): ?RegistrationsRange
    {
        return $this->requiredRange;
    }

    public function setRequiredRange(?RegistrationsRange $requiredRange): void
    {
        $this->requiredRange = $requiredRange;
    }

    public function getPrice(?ParticipantType $participantType = null): int
    {
        if (null === $participantType) {
            return $this->traitGetPrice();
        }
        $price = $this->getParticipantType() === $participantType ? $this->traitGetPrice() : 0;
        $price += $this->getRequiredRangePrice($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
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

    public function getRequiredRangeDeposit(?ParticipantType $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRange()) ? 0 : $this->getRequiredRange()->getDepositValue($participantType);
    }

    public function getDepositValue(?ParticipantType $participantType = null): int
    {
        if (null === $participantType) {
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
        $this->simulateParticipantAdd($onlyPublic, $max);
        if (null !== $participant) {
            $this->simulateFlagsAdd($participant->getFlagsAggregatedByType(), $onlyPublic, $max);
        }
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
        if (!$this->containsDateTimeInRange()) {
            $rangeText = $this->getRangeAsText();
            throw new EventCapacityExceededException("Přihlášky v tomto rozsahu aktuálně nejsou povoleny ($rangeText).");
        }
        $remainingCapacity = $this->getRemainingCapacity($max);
        if ((null !== $remainingCapacity && 1 > $remainingCapacity) || ($onlyPublic && !$this->isPublicOnWeb())) {
            throw new EventCapacityExceededException();
        }
    }

    public function getRemainingCapacity(bool $max = false): ?int
    {
        $capacity = $this->getCapacity($max);

        return null === $capacity ? null : ($capacity - $this->getBaseUsage($max));
    }

    public function getBaseUsage(bool $max = false): int
    {
        return $max ? $this->getFullUsage() : $this->baseUsage;
    }

    public function setBaseUsage(int $baseUsage): void
    {
        $this->baseUsage = $baseUsage;
    }

    public function getFullUsage(): int
    {
        return $this->fullUsage;
    }

    public function setFullUsage(int $fulUsage): void
    {
        $this->fullUsage = $fulUsage;
    }

    /**
     * Checks capacity of flag ranges.
     *
     * @param array|null $flagsAggregatedByType
     * @param bool       $onlyPublic
     * @param bool|false $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateFlagsAdd(?array $flagsAggregatedByType = null, bool $onlyPublic = true, bool $max = false): void
    {
        $this->checkFlagsExistence($flagsAggregatedByType, $this->getFlagsAggregatedByType());
        $this->checkFlagsRanges($flagsAggregatedByType, $this->getFlagsAggregatedByType());
        foreach ($flagsAggregatedByType as $typeSlug => $flagsOfType) {
            foreach ($flagsOfType as $flagSlug => $aggregatedFlag) {
                if ($flag = ($aggregatedFlag['flag'] instanceof ParticipantFlag ? $aggregatedFlag['flag'] : null)) {
                    $flagRemainingCapacity = $this->getFlagRemainingCapacity($flag, $onlyPublic, $max);
                    if (($aggregatedFlag['count'] ?? 0) > $flagRemainingCapacity) {
                        $flagName = $flag->getName();
                        throw new EventCapacityExceededException("Kapacita příznaku \"$flagName\" byla vyčerpána.");
                    }
                }
            }
        }
    }

    /**
     * Checks if type of each used flag can be used in event.
     *
     * @param array $flagsByTypes
     * @param array $allowedFlagsByTypes
     *
     * @throws EventCapacityExceededException
     */
    private function checkFlagsExistence(array $flagsByTypes, array $allowedFlagsByTypes): void
    {
        foreach ($flagsByTypes as $flagTypeSlug => $flagsOfType) {
            $firstFlagOfType = $flagsOfType['flag'] ?? null;
            if ($firstFlagOfType instanceof ParticipantFlag) {
                $flagType = $firstFlagOfType->getFlagType();
                if ($flagType && !array_key_exists($flagType->getSlug(), $allowedFlagsByTypes)) {
                    $flagTypeName = $flagType->getName();
                    $message = "Příznak typu $flagTypeName není u této přihlášky povolen.";
                    throw new EventCapacityExceededException($message);
                }
            }
        }
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

    /**
     * Checks if the amount of flags of each type meets range of that type.
     *
     * @param array $flagsByTypes
     * @param array $allowedFlagsByTypes
     *
     * @throws EventCapacityExceededException
     */
    private function checkFlagsRanges(array $flagsByTypes, array $allowedFlagsByTypes): void
    {
        foreach ($allowedFlagsByTypes as $flagsOfType) {
            $flagType = $flagsOfType['flagType'] instanceof ParticipantFlagType ? $flagsOfType['flagType'] : null;
            $flagTypeSlug = $flagType ? $flagType->getSlug() : '0';
            $flagsOfTypeAmount = $flagsByTypes[$flagTypeSlug]['count'];
            $min = $flagType ? $flagType->getMinInParticipant() ?? 0 : 0;
            $max = $flagType ? $flagType->getMaxInParticipant() : null;
            if (null !== $flagType && ($min > $flagsOfTypeAmount || (null !== $max && $max < $flagsOfTypeAmount))) {
                $maxMessage = null === $max ? '' : "až $max";
                throw new EventCapacityExceededException("Musí být vybráno $min $maxMessage příznaků typu ".$flagType->getName().".");
            }
        }
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

    public function isApplicableByTypeOfType(?string $participantType = null, ?DateTime $dateTime = null): bool
    {
        if (null !== $participantType && (null === $this->getParticipantType() || $this->getParticipantType()->getType() !== $participantType)) {
            return false;
        }

        return null === $dateTime || $this->containsDateTimeInRange($dateTime);
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
        return $this->containsDateTimeInRange() && (null === $this->getCapacity($max) || 0 < $this->getCapacity($max));
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
            if ($range instanceof ParticipantFlagRange && $range->getRemainingCapacity($max) !== 0) {
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
        if ($flagRange->getBaseUsage() > 0) {
            throw new OswisNotImplementedException('Nelze odebrat již využitý rozsah.');
        }
        $this->getFlagRanges()->remove($flagRange);
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
        if ($this->event === $event) {
            return;
        }
        if (null === $this->event) {
            $this->event = $event;
        }
        throw new OswisNotImplementedException('změna události', 'v rozsahu registrací');
    }
}
