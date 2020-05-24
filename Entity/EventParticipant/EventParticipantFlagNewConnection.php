<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use DateTime;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DateTimeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag_new_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantFlagNewConnection implements BasicInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DateTimeTrait;

    /**
     * Event contact flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantFlag $eventParticipantFlag = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     inversedBy="participantFlagConnections"
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
            $this->eventParticipant->removeParticipantFlagConnection($this);
        }
        if ($eventParticipant && $this->eventParticipant !== $eventParticipant) {
            $this->eventParticipant = $eventParticipant;
            $eventParticipant->addParticipantFlagConnection($this);
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
