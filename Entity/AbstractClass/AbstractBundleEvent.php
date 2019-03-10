<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

abstract class AbstractBundleEvent
{
    /*
    public const ALLOWED_TYPES = [
        'year-of-event'        => ['name' => 'Ročník akce', 'color' => '#000000'],
        'lecture'              => ['name' => 'Přednáška', 'color' => '#0000FF'],
        'workshop'             => ['name' => 'Workshop', 'color' => '#00FF00'],
        'moderated-discussion' => ['name' => 'Moderovaná diskuze', 'color' => '#FF0000'],
    ];
    */

    use BasicEntityTrait;

    /**
     * @var Event|null $parentEvent
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
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
