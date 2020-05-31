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
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlagRange;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\EventCapacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\EventPrice;
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
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_registration_range")
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
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantType $participantType = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlagRange")
     * @Doctrine\ORM\Mapping\JoinTable(
     *      name="registrations_ranges_participant_flag_ranges",
     *      joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="registrations_range_id", referencedColumnName="id")},
     *      inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_flag_range_id", referencedColumnName="id")}
     * )
     */
    protected ?Collection $allowedFlagRanges = null;

    /**
     * Indicates that price is relative to super event,
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $relative = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null,
        ?ParticipantType $participantType = null,
        ?EventPrice $eventPrice = null,
        ?DateTimeRange $dateTimeRange = null,
        ?bool $isRelative = null,
        ?RegistrationsRange $requiredRange = null,
        ?EventCapacity $eventCapacity = null,
        Publicity $publicity = null
    ) {
        $this->allowedFlagRanges = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setEvent($event);
        $this->setParticipantType($participantType);
        $this->setEventPrice($eventPrice);
        $this->setStartDateTime($dateTimeRange ? $dateTimeRange->startDateTime : null);
        $this->setEndDateTime($dateTimeRange ? $dateTimeRange->endDateTime : null, true);
        $this->setEventCapacity($eventCapacity);
        $this->setRelative($isRelative);
        $this->setRequiredRange($requiredRange);
        $this->setFieldsFromPublicity($publicity);
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
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

    public function getCapacityWithOverflow(): ?int
    {
        return null === $this->getCapacity() ? null : $this->getCapacity() + $this->getCapacityOverflowLimit();
    }

    public function getParticipantType(): ?ParticipantType
    {
        return $this->participantType;
    }

    public function setParticipantType(?ParticipantType $participantType): void
    {
        $this->participantType = $participantType;
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

    public function getRequiredRange(): ?RegistrationsRange
    {
        return $this->requiredRange;
    }

    public function setRequiredRange(?RegistrationsRange $requiredRange): void
    {
        $this->requiredRange = $requiredRange;
    }

    public function isRangeActive(): bool
    {
        return $this->containsDateTimeInRange() && (null === $this->getCapacity() || 0 < $this->getCapacity());
    }

    public function getAllowedFlagRanges(?ParticipantFlagType $flagType = null, ?ParticipantFlag $flag = null, ?bool $onlyPublic = false): Collection
    {
        $flagRanges = $this->allowedFlagRanges ?? new ArrayCollection();
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
        return $this->getAllowedFlagRanges(null, $flag, $onlyPublic)->filter(
            fn(ParticipantFlagRange $range) => $range->containsFlagOfTypeString($flagType)
        );
    }

    public function addAllowedFlagRange(?ParticipantFlagRange $flagRange): void
    {
        if (null !== $flagRange && !$this->getAllowedFlagRanges()->contains($flagRange)) {
            $this->getAllowedFlagRanges()->add($flagRange);
        }
    }

    public function getFlagRange(ParticipantFlag $flag, bool $withOverflow = false, bool $onlyPublic = false): ?ParticipantFlagRange
    {
        foreach ($this->getAllowedFlagRanges(null, $flag, $onlyPublic) as $range) {
            if ($range instanceof ParticipantFlagRange && $range->hasRemainingCapacity($withOverflow)) {
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
    public function removeAllowedFlagRange(?ParticipantFlagRange $flagRange): void
    {
        if (null === $flagRange) {
            return;
        }
        if ($flagRange->getUsage() > 0) {
            throw new OswisNotImplementedException('Nelze odebrat již využitý rozsah.');
        }
        $this->getAllowedFlagRanges()->remove($flagRange);
    }
}
