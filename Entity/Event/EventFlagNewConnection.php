<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use DateTime;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_flag_new_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventFlagNewConnection
{
    use BasicEntityTrait;
    use TextValueTrait;
    use DateRangeTrait;

    /**
     * Event flag.
     * @var EventFlag|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventFlag",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventFlag $eventFlag = null;

    /**
     * Event.
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="eventFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * FlagInEmployerInEvent constructor.
     *
     * @param EventFlag|null $eventFlag
     * @param Event|null     $event
     * @param string|null    $textValue
     * @param DateTime|null  $startDateTime
     * @param DateTime|null  $endDateTie
     */
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

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventFlagConnection($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventFlagConnection($this);
        }
    }

    final public function getEventFlag(): ?EventFlag
    {
        return $this->eventFlag;
    }

    final public function setEventFlag(?EventFlag $eventFlag): void
    {
        $this->eventFlag = $eventFlag;
    }
}
