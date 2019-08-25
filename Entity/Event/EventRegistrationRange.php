<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use DateTime;
use Exception;
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
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="eventRegistrationRanges",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $event;

    /**
     * @var EventParticipantType|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     inversedBy="eventRegistrationRanges",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventParticipantType;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null             $nameable
     * @param Event|null                $event
     * @param EventParticipantType|null $eventParticipantType
     * @param DateTime|null             $startDateTime
     * @param DateTime|null             $endDateTime
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null,
        ?EventParticipantType $eventParticipantType = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null
    ) {
        $this->setEvent($event);
        $this->setFieldsFromNameable($nameable);
        $this->setEventParticipantType($eventParticipantType);
        $this->setStartDateTime($startDateTime);
        $this->setEndDateTime($endDateTime);
    }

    final public function getEvent(): Event
    {
        return $this->event;
    }

    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventRegistrationRange($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventRegistrationRange($this);
        }
    }

    final public function isApplicable(?EventParticipantType $eventParticipantType = null, ?DateTime $referenceDateTime = null): bool
    {
        try {
            if ($eventParticipantType && !$this->getEventParticipantType()) {
                return false;
            }
            if ($eventParticipantType && $this->getEventParticipantType()->getId() !== $eventParticipantType->getId()) {
                return false;
            }
            if (!$this->containsDateTimeInRange($referenceDateTime)) {
                return false;
            }
        } catch (Exception $e) {
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
        if ($this->eventParticipantType && $eventParticipantType !== $this->eventParticipantType) {
            $this->eventParticipantType->removeEventRegistrationRange($this);
        }
        if ($eventParticipantType && $this->eventParticipantType !== $eventParticipantType) {
            $this->eventParticipantType = $eventParticipantType;
            $eventParticipantType->addEventRegistrationRange($this);
        }
    }
}
