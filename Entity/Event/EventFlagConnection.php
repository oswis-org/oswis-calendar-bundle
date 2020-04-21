<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use DateTime;
use OswisOrg\OswisCoreBundle\Interfaces\BasicEntityInterface;
use OswisOrg\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_flag_new_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventFlagConnection implements BasicEntityInterface
{
    use BasicEntityTrait;
    use TextValueTrait;
    use DateRangeTrait;

    /**
     * Event flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventFlag", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventFlag $eventFlag = null;

    /**
     * Event.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", inversedBy="eventFlagConnections")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    public function __construct(
        ?EventFlag $eventFlag = null,
        ?Event $event = null,
        ?string $textValue = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTie = null
    ) {
        $this->setEventFlag($eventFlag);
        $this->setEvent($event);
        $this->setTextValue($textValue);
        $this->setStartDateTime($startDateTime);
        $this->setEndDateTime($endDateTie);
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventFlagConnection($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventFlagConnection($this);
        }
    }

    public function getEventFlag(): ?EventFlag
    {
        return $this->eventFlag;
    }

    public function setEventFlag(?EventFlag $eventFlag): void
    {
        $this->eventFlag = $eventFlag;
    }
}
