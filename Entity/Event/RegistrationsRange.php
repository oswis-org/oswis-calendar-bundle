<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use DateTime;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\EventCapacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\EventPrice;
use OswisOrg\OswisCalendarBundle\Traits\Entity\EventCapacityTrait;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NumericValueTrait;
use OswisOrg\OswisCoreBundle\Traits\Payment\DepositValueTrait;

/**
 * Time range available for registrations of participants of some type to some event (with some price, capacity...).
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_price")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class RegistrationsRange implements NameableInterface
{
    use NameableTrait;
    use NumericValueTrait;
    use DepositValueTrait;
    use EventCapacityTrait;
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
     *     targetEntity="ParticipantType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantType $participantType = null;

    /**
     * Indicates that price is relative to super event,
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $relative = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?ParticipantType $participantType = null,
        ?EventPrice $eventPrice = null,
        ?DateTimeRange $dateTimeRange = null,
        ?bool $isRelative = null,
        ?bool $superEventRequired = null,
        ?EventCapacity $eventCapacity = null,
        Publicity $publicity = null
    ) {
        $this->setParticipantType($participantType);
        $this->setFieldsFromNameable($nameable);
        if (null !== $eventPrice) {
            $this->setNumericValue($eventPrice->price);
            $this->setDepositValue($eventPrice->deposit);
        }
        if (null !== $dateTimeRange) {
            $this->setStartDateTime($dateTimeRange->startDateTime);
            $this->setEndDateTime($dateTimeRange->endDateTime, true);
        }
        $this->setEventCapacity($eventCapacity);
        $this->setRelative($isRelative);
        $this->setSuperEventRequired($superEventRequired);
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
        if ($participantType && null === $this->getParticipantType()) {
            return false;
        }
        if ($participantType && $this->getParticipantType()->getType() !== $participantType) {
            return false;
        }

        return null === $dateTime || $this->containsDateTimeInRange($dateTime);
    }

    public function isRelative(): bool
    {
        return $this->getRelative();
    }

    public function getRelative(): bool
    {
        return $this->relative ?? false;
    }

    public function setRelative(?bool $relative): void
    {
        $this->relative = $relative;
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
