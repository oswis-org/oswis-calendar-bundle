<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use DateTime;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_registration_range")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventRegistrationRange
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use DateRangeTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantType $eventParticipantType = null;

    public function __construct(?Nameable $nameable = null, ?EventParticipantType $eventParticipantType = null, ?DateTime $startDateTime = null, ?DateTime $endDateTime = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setEventParticipantType($eventParticipantType);
        $this->setStartDateTime($startDateTime);
        $this->setEndDateTime($endDateTime);
    }

    final public function isApplicable(?EventParticipantType $eventParticipantType = null, ?DateTime $referenceDateTime = null): bool
    {
        if ($eventParticipantType && !$this->getEventParticipantType()) {
            return false;
        }
        if ($eventParticipantType && $this->getEventParticipantType()->getId() !== $eventParticipantType->getId()) {
            return false;
        }
        if (!$this->containsDateTimeInRange($referenceDateTime)) {
            return false;
        }

        return true;
    }

    final public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    final public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        $this->eventParticipantType = $eventParticipantType;
    }
}
