<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_registration_range")
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
     * @param Nameable|null $nameable
     * @param Event|null    $event
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null
    ) {
        $this->setEvent($event);
        $this->setFieldsFromNameable($nameable);
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
