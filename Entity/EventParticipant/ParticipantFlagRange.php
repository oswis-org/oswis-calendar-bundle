<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_flag_in_event_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantFlagRange implements BasicInterface
{
    use BasicTrait;
    use EntityPublicTrait;

    /**
     * Capacity (max usages of this flag in event).
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $capacity = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlag",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantFlag $participantFlag = null;

    /**
     * Event contact (connected to person or organization).
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="participantFlagRanges"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * Event contact type.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="ParticipantType", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantType $participantType = null;

    /**
     * @param ParticipantFlag|null $participantFlag
     * @param Event|null           $event
     * @param ParticipantType|null $participantType
     * @param int|null             $capacity
     * @param Publicity|null       $publicity
     */
    public function __construct(
        ?ParticipantFlag $participantFlag = null,
        ?Event $event = null,
        ?ParticipantType $participantType = null,
        ?int $capacity = null,
        ?Publicity $publicity = null
    ) {
        $this->setParticipantFlag($participantFlag);
        $this->setParticipantType($participantType);
        $this->setEvent($event);
        $this->setCapacity($capacity);
        $this->setFieldsFromPublicity($publicity);
    }

    public function getParticipantType(): ?ParticipantType
    {
        return $this->participantType;
    }

    public function setParticipantType(?ParticipantType $participantType): void
    {
        $this->participantType = $participantType;
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(?int $capacity): void
    {
        $this->capacity = $capacity;
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

    public function getParticipantFlag(): ?ParticipantFlag
    {
        return $this->participantFlag;
    }

    public function setParticipantFlag(?ParticipantFlag $participantFlag): void
    {
        $this->participantFlag = $participantFlag;
    }
}
