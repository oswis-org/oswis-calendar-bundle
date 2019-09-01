<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use DateTime;
use Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateTimeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag_new_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantFlagNewConnection
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
     * @var EventParticipant|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     inversedBy="eventParticipantFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventParticipant;

    /**
     * FlagInEmployerInEvent constructor.
     *
     * @param EventParticipantFlag|null $eventParticipantFlag
     * @param EventParticipant|null     $eventParticipant
     * @param string|null               $textValue
     * @param DateTime|null             $dateTime
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?EventParticipantFlag $eventParticipantFlag = null,
        ?EventParticipant $eventParticipant = null,
        ?string $textValue = null,
        ?DateTime $dateTime = null
    ) {
        $this->setEventParticipantFlag($eventParticipantFlag);
        $this->setEventParticipant($eventParticipant);
        $this->setTextValue($textValue);
        $this->setDateTime($dateTime);
    }

    final public function getEventParticipant(): ?EventParticipant
    {
        return $this->eventParticipant;
    }

    /**
     * @param EventParticipant|null $eventParticipant
     *
     * @throws EventCapacityExceededException
     */
    final public function setEventParticipant(?EventParticipant $eventParticipant): void
    {
        if ($this->eventParticipant && $eventParticipant !== $this->eventParticipant) {
            $this->eventParticipant->removeEventParticipantFlagConnection($this);
        }
        if ($eventParticipant && $this->eventParticipant !== $eventParticipant) {
            $this->eventParticipant = $eventParticipant;
            $eventParticipant->addEventParticipantFlagConnection($this);
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
