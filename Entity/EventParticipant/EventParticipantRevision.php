<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use function assert;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_revision")
 */
class EventParticipantRevision extends AbstractRevision
{
    use BasicEntityTrait;

    /**
     * @var EventParticipant
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     inversedBy="revisions"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="container_id", referencedColumnName="id")
     */
    protected $container;

    /**
     * Related contact (person or organization).
     * @var AbstractContact|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $contact;

    /**
     * Related event.
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="eventParticipantRevisions",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $event;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagConnection",
     *     cascade={"all"},
     *     mappedBy="eventContactRevision",
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantFlagConnections;

    /**
     * EventAttendee constructor.
     *
     * @param AbstractContact|null $contact
     * @param Event|null           $event
     * @param Collection|null      $eventContactFlagConnections
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?AbstractContact $contact = null,
        ?Event $event = null,
        ?Collection $eventContactFlagConnections = null
    ) {
        $this->eventParticipantFlagConnections = new ArrayCollection();
        $this->setContact($contact);
        $this->setEvent($event);
        $this->setEventParticipantFlagConnections($eventContactFlagConnections);
    }

    /**
     * @return string
     */
    public static function getRevisionContainerClassName(): string
    {
        return EventParticipant::class;
    }

    /**
     * @param AbstractRevisionContainer|null $revision
     */
    public static function checkRevisionContainer(?AbstractRevisionContainer $revision): void
    {
        assert($revision instanceof EventParticipant);
    }

    public function __clone()
    {
        foreach ($this->eventParticipantFlagConnections as $eventParticipantFlagConnection) {
            $newEventParticipantFlagConnection = clone $eventParticipantFlagConnection;
            assert($newEventParticipantFlagConnection instanceof EventParticipantFlagConnection);
            $newEventParticipantFlagConnection->setEventContactRevision($this);
        }
    }

    final public function addEventParticipantFlagConnection(?EventParticipantFlagConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && !$this->eventParticipantFlagConnections->contains($eventContactFlagConnection)) {
            $this->eventParticipantFlagConnections->add($eventContactFlagConnection);
            $eventContactFlagConnection->setEventContactRevision($this);
        }
    }

    final public function removeEventParticipantFlagConnection(?EventParticipantFlagConnection $eventContactFlagConnection): void
    {
        if (!$eventContactFlagConnection) {
            return;
        }
        if ($this->eventParticipantFlagConnections->removeElement($eventContactFlagConnection)) {
            $eventContactFlagConnection->setEventContactRevision(null);
        }
    }

    final public function getContact(): ?AbstractContact
    {
        return $this->contact;
    }

    final public function setContact(?AbstractContact $contact): void
    {
        $this->contact = $contact;
    }

    /**
     * @return int
     * @throws PriceInvalidArgumentException
     */
    final public function getPrice(): int
    {
        $participant = $this->getContainer();
        assert($participant instanceof EventParticipant);
        if (!$participant || !$this->getEvent() || !$participant->getEventParticipantType()) {
            throw new PriceInvalidArgumentException();
        }
        $price = $this->getEvent()->getPrice($participant->getEventParticipantType());
        $price += $this->getFlagsPrice();

        return $price < 0 ? 0 : $price;
    }

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * @param Event|null $event
     *
     * @throws EventCapacityExceededException
     */
    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventParticipantRevision($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventParticipantRevision($this);
        }
    }

    final public function getFlagsPrice(): int
    {
        $price = 0;
        foreach ($this->getEventParticipantFlagConnections() as $eventParticipantFlagConnection) {
            assert($eventParticipantFlagConnection instanceof EventParticipantFlagConnection);
            $eventParticipantFlag = $eventParticipantFlagConnection->getEventParticipantFlag();
            if (!$eventParticipantFlag) {
                continue;
            }
            $price += $eventParticipantFlag->getPrice();
        }

        return $price;
    }

    final public function getEventParticipantFlagConnections(): Collection
    {
        return $this->eventParticipantFlagConnections ?? new ArrayCollection();
    }

    final public function setEventParticipantFlagConnections(?Collection $newEventContactFlagConnections): void
    {
        if (!$this->eventParticipantFlagConnections) {
            $this->eventParticipantFlagConnections = new ArrayCollection();
        }
        if (!$newEventContactFlagConnections) {
            $newEventContactFlagConnections = new ArrayCollection();
        }
        foreach ($this->eventParticipantFlagConnections as $oldEventContactFlagConnection) {
            if (!$newEventContactFlagConnections->contains($oldEventContactFlagConnection)) {
                $this->removeEventParticipantFlagConnection($oldEventContactFlagConnection);
            }
        }
        if ($newEventContactFlagConnections) {
            foreach ($newEventContactFlagConnections as $newEventContactFlagConnection) {
                if (!$this->eventParticipantFlagConnections->contains($newEventContactFlagConnection)) {
                    $this->addEventParticipantFlagConnection($newEventContactFlagConnection);
                }
            }
        }
    }

    /**
     * @return int
     * @throws PriceInvalidArgumentException
     */
    final public function getDeposit(): int
    {
        $participant = $this->getContainer();
        assert($participant instanceof EventParticipant);
        if (!$participant || !$this->getEvent() || !$participant->getEventParticipantType()) {
            throw new PriceInvalidArgumentException();
        }
        $price = $this->getEvent()->getDeposit($participant->getEventParticipantType());

        return $price < 0 ? 0 : $price;
    }
}
