<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use Doctrine\ORM\Mapping as ORM;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActiveTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag_in_event_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantFlagInEventConnection implements BasicInterface
{
    use BasicTrait;
    use ActiveTrait;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected ?int $maxAmountInEvent = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantFlag $eventParticipantFlag = null;

    /**
     * Event contact (connected to person or organization).
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="participantFlagInEventConnections"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * Event contact type.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantType $eventParticipantType = null;

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
        $this->setEventParticipantType($eventParticipantType);
        $this->setEvent($event);
        $this->setMaxAmountInEvent($maxAmountInEvent);
    }

    public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        $this->eventParticipantType = $eventParticipantType;
    }

    public function getMaxAmountInEvent(): ?int
    {
        return $this->maxAmountInEvent;
    }

    public function setMaxAmountInEvent(?int $maxAmountInEvent): void
    {
        $this->maxAmountInEvent = $maxAmountInEvent;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeParticipantFlagInEventConnection($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addParticipantFlagInEventConnection($this);
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
