<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use DateTime;
use Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateTimeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantFlagConnection
{
    use BasicEntityTrait;
    use TextValueTrait;
    use DateTimeTrait;

    /**
     * Event contact flag.
     * @var EventParticipantFlag|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag",
     *     inversedBy="eventParticipantFlagNewConnections",
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
    protected $eventParticipantRevision;

    /**
     * FlagInEmployerInEvent constructor.
     *
     * @param EventParticipantFlag|null     $eventContactFlag
     * @param EventParticipantRevision|null $eventContactRevision
     *
     * @param string|null                   $textValue
     * @param DateTime|null                 $dateTime
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?EventParticipantFlag $eventContactFlag = null,
        ?EventParticipantRevision $eventContactRevision = null,
        ?string $textValue = null,
        ?DateTime $dateTime = null
    ) {
        $this->setEventParticipantFlag($eventContactFlag);
        $this->setEventParticipantRevision($eventContactRevision);
        $this->setTextValue($textValue);
        $this->setDateTime($dateTime);
    }

    final public function getEventParticipantRevision(): ?EventParticipantRevision
    {
        return $this->eventParticipantRevision;
    }

    /**
     * @param EventParticipantRevision|null $eventParticipantRevision
     *
     * @throws EventCapacityExceededException
     */
    final public function setEventParticipantRevision(?EventParticipantRevision $eventParticipantRevision): void
    {
        if ($this->eventParticipantRevision && $eventParticipantRevision !== $this->eventParticipantRevision) {
            $this->eventParticipantRevision->removeEventParticipantFlagConnection($this);
        }
        if ($eventParticipantRevision && $this->eventParticipantRevision !== $eventParticipantRevision) {
            $this->eventParticipantRevision = $eventParticipantRevision;
            $eventParticipantRevision->addEventParticipantFlagConnection($this);
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
