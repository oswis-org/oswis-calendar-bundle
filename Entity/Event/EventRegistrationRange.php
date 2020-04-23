<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use DateTime;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use OswisOrg\OswisCalendarBundle\Traits\Entity\EventCapacityTrait;
use OswisOrg\OswisCoreBundle\Entity\Nameable;
use OswisOrg\OswisCoreBundle\Entity\Publicity;
use OswisOrg\OswisCoreBundle\Interfaces\BasicEntityInterface;
use OswisOrg\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\DepositValueTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\NumericValueTrait;

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
    use EntityPublicTrait;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?bool $superEventRequired;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
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
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null,
        ?bool $isRelative = null,
        ?bool $superEventRequired = null,
        ?int $capacity = null,
        ?int $capacityOverflowLimit = null,
        Publicity $publicity = null
    ) {
        $this->setEventParticipantType($participantType);
        $this->setNumericValue($numericValue);
        $this->setFieldsFromNameable($nameable);
        $this->setDepositValue($depositValue);
        $this->setStartDateTime($startDateTime);
        $this->setEndDateTime($endDateTime, true);
        $this->setIsRelative($isRelative);
        $this->setSuperEventRequired($superEventRequired);
        $this->setCapacity($capacity);
        $this->setCapacityOverflowLimit($capacityOverflowLimit);
        $this->setFieldsFromPublicity($publicity);
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

    public function getNumericValueRecursive(): int
    {
        // TODO: Invent it.
        return $this->getNumericValue();
    }

    public function isApplicableByType(?EventParticipantType $participantType = null, ?DateTime $dateTime = null): bool
    {
        if (null !== $participantType && null === $this->getEventParticipantType()) {
            return false;
        }
        if (null !== $participantType && $this->getEventParticipantType()
                ->getId() !== $participantType->getId()) {
            return false;
        }
        if (null !== $dateTime && !$this->containsDateTimeInRange($dateTime)) {
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

    public function isApplicableByTypeOfType(?string $participantType = null, ?DateTime $dateTime = null): bool
    {
        if ($participantType && null === $this->getEventParticipantType()) {
            return false;
        }
        if ($participantType && $this->getEventParticipantType()
                ->getType() !== $participantType) {
            return false;
        }

        return null === $dateTime || $this->containsDateTimeInRange($dateTime);
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

    public function isRangeActive(): bool
    {
        return $this->containsDateTimeInRange() && $this->getCapacity() > 0;
    }
}
