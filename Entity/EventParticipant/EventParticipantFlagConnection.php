<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag_connection")
 */
class EventParticipantFlagConnection
{
    use BasicEntityTrait;
    use TextValueTrait;

    /**
     * @var bool
     */
    public $selected;

    /**
     * Event contact flag.
     * @var EventParticipantFlag|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag",
     *     inversedBy="eventParticipantFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventParticipantFlag;

    /**
     * Event contact revision (connected to person or organization).
     * @var EventParticipantRevision|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantRevision",
     *     inversedBy="eventParticipantFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventContactRevision;

    /**
     * FlagInEmployerInEvent constructor.
     *
     * @param EventParticipantFlag|null     $eventContactFlag
     * @param EventParticipantRevision|null $eventContactRevision
     */
    public function __construct(
        ?EventParticipantFlag $eventContactFlag = null,
        ?EventParticipantRevision $eventContactRevision = null
    ) {
        $this->setEventParticipantFlag($eventContactFlag);
        $this->setEventContactRevision($eventContactRevision);
    }

    final public function getEventContactRevision(): ?EventParticipantRevision
    {
        return $this->eventContactRevision;
    }

    /**
     * @param EventParticipantRevision|null $eventContactRevision
     *
     * @throws \Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException
     */
    final public function setEventContactRevision(?EventParticipantRevision $eventContactRevision): void
    {
        if ($this->eventContactRevision && $eventContactRevision !== $this->eventContactRevision) {
            $this->eventContactRevision->removeEventParticipantFlagConnection($this);
        }
        if ($eventContactRevision && $this->eventContactRevision !== $eventContactRevision) {
            $this->eventContactRevision = $eventContactRevision;
            $eventContactRevision->addEventParticipantFlagConnection($this);
        }
    }

    final public function getEventParticipantFlag(): ?EventParticipantFlag
    {
        return $this->eventParticipantFlag;
    }

    final public function setEventParticipantFlag(?EventParticipantFlag $eventParticipantFlag): void
    {
        if ($this->eventParticipantFlag && $eventParticipantFlag !== $this->eventParticipantFlag) {
            $this->eventParticipantFlag->removeEventParticipantFlagConnection($this);
        }
        if ($eventParticipantFlag && $this->eventParticipantFlag !== $eventParticipantFlag) {
            $this->eventParticipantFlag = $eventParticipantFlag;
            $eventParticipantFlag->addEventParticipantFlagConnection($this);
        }
    }
}
