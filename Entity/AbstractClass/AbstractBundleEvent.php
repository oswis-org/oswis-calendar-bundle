<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

abstract class AbstractBundleEvent
{
    use BasicEntityTrait;

    /**
     * @var Event|null $parentEvent
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     cascade={"persist"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $event;

    /**
     * AbstractBundleEvent constructor.
     *
     * @param Event|null $event
     */
    public function __construct(
        ?Event $event = null
    ) {
        $this->setEvent($event);
    }

    final public function getName(): ?string
    {
        return $this->getEvent() ? $this->getEvent()->getName() : null;
    }

    /**
     * @return Event|null
     */
    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * @param Event|null $event
     */
    final public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }
}
