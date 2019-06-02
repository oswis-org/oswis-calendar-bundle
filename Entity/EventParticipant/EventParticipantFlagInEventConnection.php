<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use Doctrine\ORM\Mapping as ORM;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag_in_event_connection")
 */
class EventParticipantFlagInEventConnection
{
    use BasicEntityTrait;

    /**
     * @var int|null
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $maxAmountInEvent;

    /**
     * @var EventParticipantFlag|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag",
     *     inversedBy="eventParticipantFlagInEventConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventParticipantFlag;

    /**
     * Event contact (connected to person or organization).
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="eventParticipantFlagInEventConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $event;

    /**
     * Event contact type.
     * @var EventParticipantType|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     inversedBy="eventParticipantFlagInEventConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventParticipantType;

    /**
     * @param EventParticipantFlag|null $eventParticipantFlag
     * @param Event|null                $event
     * @param EventParticipantType|null $eventParticipantType
     * @param int|null                  $maxAmountInEvent
     */
    public function __construct(
        ?EventParticipantFlag $eventParticipantFlag = null,
        ?Event $event = null,
        ?EventParticipantType $eventParticipantType = null,
        ?int $maxAmountInEvent = null
    ) {
        $this->setEventParticipantFlag($eventParticipantFlag);
        $this->setEvent($event);
        $this->setMaxAmountInEvent($maxAmountInEvent);
        $this->setEventParticipantType($eventParticipantType);
    }

    final public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    final public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        if ($this->eventParticipantType && $eventParticipantType !== $this->eventParticipantType) {
            $this->eventParticipantType->removeEventParticipantFlagInEventConnection($this);
        }
        if ($eventParticipantType && $this->eventParticipantType !== $eventParticipantType) {
            $this->eventParticipantType = $eventParticipantType;
            $eventParticipantType->addEventParticipantFlagInEventConnection($this);
        }
    }

    /**
     * @return int|null
     */
    final public function getMaxAmountInEvent(): ?int
    {
        return $this->maxAmountInEvent;
    }

    /**
     * @param int|null $maxAmountInEvent
     */
    final public function setMaxAmountInEvent(?int $maxAmountInEvent): void
    {
        $this->maxAmountInEvent = $maxAmountInEvent;
    }

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventParticipantFlagInEventConnection($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventParticipantFlagInEventConnection($this);
        }
    }

    final public function getEventParticipantFlag(): ?EventParticipantFlag
    {
        return $this->eventParticipantFlag;
    }

    final public function setEventParticipantFlag(?EventParticipantFlag $eventParticipantFlag): void
    {
        if ($this->eventParticipantFlag && $eventParticipantFlag !== $this->eventParticipantFlag) {
            $this->eventParticipantFlag->removeEventParticipantFlagInEventConnection($this);
        }
        if ($eventParticipantFlag && $this->eventParticipantFlag !== $eventParticipantFlag) {
            $this->eventParticipantFlag = $eventParticipantFlag;
            $eventParticipantFlag->addEventParticipantFlagInEventConnection($this);
        }
    }

}
