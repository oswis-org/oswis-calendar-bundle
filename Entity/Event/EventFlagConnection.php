<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use OswisOrg\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlagConnection;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_flag_new_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventFlagConnection extends AbstractEventFlagConnection
{
    /**
     * Event flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventFlag", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventFlag $eventFlag = null;

    /**
     * Event.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", inversedBy="flagConnections")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    public function __construct(
        ?EventFlag $eventFlag = null,
        ?Event $event = null,
        ?string $textValue = null,
        ?DateTimeRange $dateTimeRange = null
    ) {
        parent::__construct($textValue, $dateTimeRange);
        $this->setEventFlag($eventFlag);
        $this->setEvent($event);
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeFlagConnection($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addFlagConnection($this);
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
