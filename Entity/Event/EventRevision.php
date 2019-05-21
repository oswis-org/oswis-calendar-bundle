<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use DateTime;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use function assert;

class EventRevision extends AbstractRevision
{

    use BasicEntityTrait;
    use NameableBasicTrait;
    use DateRangeTrait;

    /**
     * @var Event
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
     *     inversedBy="revisions"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="container_id", referencedColumnName="id")
     */
    protected $container;

    /**
     * @var Place|null $location
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisAddressBookBundle\Entity\Place",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $location;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventFlagConnection",
     *     cascade={"all"},
     *     mappedBy="eventContactRevision",
     *     fetch="EAGER"
     * )
     */
    protected $eventFlagConnections;

    /**
     * Type of this event.
     * @var EventType|null $eventType
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventType",
     *     inversedBy="eventRevisions",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="type_id", referencedColumnName="id")
     */
    private $eventType;

    /**
     * EventRevision constructor.
     *
     * @param Nameable|null  $nameable
     * @param Place|null     $location
     * @param EventType|null $eventType
     * @param DateTime|null  $startDateTime
     * @param DateTime|null  $endDateTime
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Place $location = null,
        ?EventType $eventType = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null
    ) {
        $this->setFieldsFromNameable($nameable);
        $this->setStartDateTime($startDateTime);
        $this->setEndDate($endDateTime);
        $this->setLocation($location);
        $this->setEventType($eventType);
    }

    /**
     * @return string
     */
    public static function getRevisionContainerClassName(): string
    {
        return Event::class;
    }

    /**
     * @param AbstractRevisionContainer|null $revision
     */
    public static function checkRevisionContainer(?AbstractRevisionContainer $revision): void
    {
        assert($revision instanceof Event);
    }

    final public function addEventFlagConnection(?EventFlagConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && !$this->eventFlagConnections->contains($eventContactFlagConnection)) {
            $this->eventFlagConnections->add($eventContactFlagConnection);
            $eventContactFlagConnection->setEventRevision($this);
        }
    }

    final public function removeEventFlagConnection(?EventFlagConnection $eventContactFlagConnection): void
    {
        if (!$eventContactFlagConnection) {
            return;
        }
        if ($this->eventFlagConnections->removeElement($eventContactFlagConnection)) {
            $eventContactFlagConnection->setEventRevision(null);
        }
    }

    /**
     * @return Place|null
     */
    final public function getLocation(): ?Place
    {
        return $this->location;
    }

    /**
     * @param Place|null $event
     */
    final public function setLocation(?Place $event): void
    {
        $this->location = $event;
    }

    /**
     * @return EventType|null
     */
    final public function getEventType(): ?EventType
    {
        return $this->eventType;
    }

    /**
     * @param EventType|null $eventType
     */
    final public function setEventType(?EventType $eventType): void
    {
        if ($this->eventType && $eventType !== $this->eventType) {
            $this->eventType->removeEvent($this);
        }
        $this->eventType = $eventType;
        if ($eventType && $this->eventType !== $eventType) {
            $eventType->addEvent($this);
        }
    }

}