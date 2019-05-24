<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_flag_connection")
 */
class EventFlagConnection
{
    use BasicEntityTrait;
    use TextValueTrait;

    /**
     * @var bool
     */
    public $selected;

    /**
     * Event flag.
     * @var EventFlag|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventFlag",
     *     inversedBy="eventFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventFlag;

    /**
     * Event.
     * @var EventRevision|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRevision",
     *     inversedBy="eventFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventRevision;

    /**
     * FlagInEmployerInEvent constructor.
     *
     * @param EventFlag|null     $eventContactFlag
     * @param EventRevision|null $eventRevision
     */
    public function __construct(
        ?EventFlag $eventContactFlag = null,
        ?EventRevision $eventRevision = null
    ) {
        $this->eventFlag = $eventContactFlag;
        $this->eventRevision = $eventRevision;
    }

    final public function getEventRevision(): ?EventRevision
    {
        return $this->eventRevision;
    }

    final public function setEventRevision(?EventRevision $eventRevision): void
    {
        if ($this->eventRevision && $eventRevision !== $this->eventRevision) {
            $this->eventRevision->removeEventFlagConnection($this);
        }
        if ($eventRevision && $this->eventRevision !== $eventRevision) {
            $this->eventRevision = $eventRevision;
            $eventRevision->addEventFlagConnection($this);
        }
    }

    final public function getEventFlag(): ?EventFlag
    {
        return $this->eventFlag;
    }

    final public function setEventFlag(?EventFlag $eventFlag): void
    {
        if ($this->eventFlag && $eventFlag !== $this->eventFlag) {
            $this->eventFlag->removeEventFlagConnection($this);
        }
        if ($eventFlag && $this->eventFlag !== $eventFlag) {
            $this->eventFlag = $eventFlag;
            $eventFlag->addEventFlagConnection($this);
        }
    }
}
