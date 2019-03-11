<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

abstract class AbstractBundleEventOrganizer
{
    use BasicEntityTrait;

    /**
     * @var EventOrganizer|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventOrganizer",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventOrganizer;

    public function __construct(
        ?EventOrganizer $eventOrganizer = null
    ) {
        $this->setEventOrganizer($eventOrganizer);
    }

    final public function getEventOrganizer(): ?EventOrganizer
    {
        return $this->eventOrganizer;
    }

    final public function setEventOrganizer(?EventOrganizer $eventOrganizer): void
    {
        $this->eventOrganizer = $eventOrganizer;
    }

}
