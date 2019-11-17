<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BankAccountTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use function assert;

/**
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="Zakjakub\OswisCalendarBundle\Repository\EventRevisionRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_revision")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventRevision extends AbstractRevision
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use DateRangeTrait;
    use ColorTrait;
    use BankAccountTrait;

    /**
     * @var Event
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
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
     * @MaxDepth(1)
     */
    protected $location;

    /**
     * MOVE!!!!!!!!!
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventFlagConnection",
     *     cascade={"all"},
     *     mappedBy="eventRevision",
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected $eventFlagConnections;

    /**
     * EventRevision constructor.
     *
     * @param Nameable|null $nameable
     * @param Place|null    $location
     * @param DateTime|null $startDateTime
     * @param DateTime|null $endDateTime
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Place $location = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null
    ) {
        $this->eventFlagConnections = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setStartDateTime($startDateTime);
        $this->setEndDate($endDateTime);
        $this->setLocation($location);
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

    /** @noinspection MethodShouldBeFinalInspection */
    public function __clone()
    {
        foreach ($this->eventFlagConnections as $eventFlagConnection) {
            $this->addEventFlagConnection(clone $eventFlagConnection);
        }
    }

    final public function addEventFlagConnection(?EventFlagConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && !$this->eventFlagConnections->contains($eventContactFlagConnection)) {
            $this->eventFlagConnections->add($eventContactFlagConnection);
            $eventContactFlagConnection->setEventRevision($this);
        }
    }

    /**
     * @return Collection|null
     */
    final public function getEventFlagConnections(): ?Collection
    {
        return $this->eventFlagConnections;
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
}
