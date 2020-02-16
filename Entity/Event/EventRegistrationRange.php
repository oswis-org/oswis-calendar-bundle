<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use DateTime;
use DateTimeInterface;
use Exception;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Traits\Entity\EventCapacityTrait;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Interfaces\BasicEntityInterface;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DepositValueTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NumericValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_price")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventRegistrationRange implements BasicEntityInterface
{
    use BasicEntityTrait;

    // Basic columns (id...).
    use NameableBasicTrait;

    // Name of this combination of price, range and capacity.
    use NumericValueTrait;

    // Basic price.
    use DepositValueTrait;

    // Absolute value of deposit.
    use EventCapacityTrait;

    // Columns for capacity and capacity overflow limit.
    use DateRangeTrait { // Range when registrations are allowed with this price.
        setEndDateTime as protected traitSetEnd;
    }

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?bool $superEventRequired;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantType $eventParticipantType = null;

    /**
     * Indicates that price is relative to super event,
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $isRelative = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?EventParticipantType $participantType = null,
        ?int $numericValue = null,
        ?int $depositValue = null,
        ?DateTimeInterface $startDateTime = null,
        ?DateTimeInterface $endDateTime = null,
        ?bool $isRelative = null,
        ?bool $superEventRequired = null,
        ?int $capacity = null,
        ?int $capacityOverflowLimit = null
    ) {
        $this->setEventParticipantType($participantType);
        $this->setNumericValue($numericValue);
        $this->setFieldsFromNameable($nameable);
        $this->setDepositValue($depositValue);
        $this->setStartDateTime($startDateTime);
        $this->setEndDateTime($endDateTime);
        $this->setIsRelative($isRelative);
        $this->setSuperEventRequired($superEventRequired);
        $this->setCapacity($capacity);
        $this->setCapacityOverflowLimit($capacityOverflowLimit);
    }

    /**
     * Sets the end of registration range (can't be set to past).
     *
     * @param DateTimeInterface|null $endDateTime
     */
    public function setEndDateTime(?DateTimeInterface $endDateTime): void
    {
        try {
            $now = new DateTime();
        } catch (Exception $e) {
            $now = null;
        }
        if ($endDateTime !== $this->getEndDate()) {
            $this->traitSetEnd(null !== $now && $endDateTime < $now ? $now : $endDateTime);
        }
    }

    public function isApplicableByType(?EventParticipantType $participantType = null, ?DateTimeInterface $dateTime = null): bool
    {
        if (null !== $participantType && null === $this->getEventParticipantType()) {
            return false;
        }
        if (null !== $participantType && $this->getEventParticipantType()->getId() !== $participantType->getId()) {
            return false;
        }
        if (!$this->containsDateTimeInRange($dateTime)) {
            return false;
        }

        return true;
    }

    public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        $this->eventParticipantType = $eventParticipantType;
    }

    public function isApplicableByTypeOfType(?string $participantType = null, ?DateTimeInterface $dateTime = null): bool
    {
        if ($participantType && null === $this->getEventParticipantType()) {
            return false;
        }
        if ($participantType && $this->getEventParticipantType()->getType() !== $participantType) {
            return false;
        }

        return $this->containsDateTimeInRange($dateTime);
    }

    public function isRelative(): bool
    {
        return $this->getIsRelative();
    }

    public function getIsRelative(): bool
    {
        return $this->isRelative ?? false;
    }

    public function setIsRelative(?bool $isRelative): void
    {
        $this->isRelative = $isRelative;
    }

    public function isSuperEventRequired(): ?bool
    {
        return $this->getSuperEventRequired();
    }

    public function getSuperEventRequired(): ?bool
    {
        return $this->superEventRequired;
    }

    public function setSuperEventRequired(?bool $superEventRequired): void
    {
        $this->superEventRequired = $superEventRequired;
    }
}
