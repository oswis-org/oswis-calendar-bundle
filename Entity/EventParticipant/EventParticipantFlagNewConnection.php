<?php /** @noinspection ALL */

/**
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use DateTime;
use Zakjakub\OswisCalendarBundle\Exception\EventCapacityExceededException;
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
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantFlag $eventParticipantFlag = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     inversedBy="eventParticipantFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipant $eventParticipant = null;

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

    public function getEventParticipant(): ?EventParticipant
    {
        return $this->eventParticipant;
    }

    /**
     * @param EventParticipant|null $eventParticipant
     *
     * @throws EventCapacityExceededException
     */
    public function setEventParticipant(?EventParticipant $eventParticipant): void
    {
        if ($this->eventParticipant && $eventParticipant !== $this->eventParticipant) {
            $this->eventParticipant->removeEventParticipantFlagConnection($this);
        }
        if ($eventParticipant && $this->eventParticipant !== $eventParticipant) {
            $this->eventParticipant = $eventParticipant;
            $eventParticipant->addEventParticipantFlagConnection($this);
        }
    }

    public function getEventParticipantFlag(): ?EventParticipantFlag
    {
        return $this->eventParticipantFlag;
    }

    public function setEventParticipantFlag(?EventParticipantFlag $eventParticipantFlag): void
    {
        $this->eventParticipantFlag = $eventParticipantFlag;
    }
}
