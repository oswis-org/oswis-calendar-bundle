<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

/**
 * @package Zakjakub\OswisCalendarBundle\Entity
 * @deprecated
 */
abstract class AbstractBundleEventOrganizer
{
    use BasicEntityTrait;

    /**
     * @var EventOrganizer|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\AbstractBundleEventOrganizer",
     *     fetch="EAGER",
     *     cascade={"all"}
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
