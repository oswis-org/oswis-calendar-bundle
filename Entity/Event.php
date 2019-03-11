<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(name="calendar_event")
 * @ApiResource(
 *   iri="http://schema.org/Place",
 *   attributes={
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"address_book_organizations_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"address_book_organizations_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"address_book_organization_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"address_book_organization_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"address_book_organization_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "description",
 *     "note"
 * })
 */
class Event
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use DateRangeTrait;

    /**
     * Parent event (if this is not top level event).
     * @var Event|null $superEvent
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
     *     inversedBy="subEvents",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $superEvent;

    /**
     * Sub events.
     * @var Collection|null $subEvents
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
     *     mappedBy="superEvent",
     *     fetch="EAGER"
     * )
     */
    protected $subEvents;

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
     * @var bool
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $registrationRequired;

    /**
     * @var bool
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $registrationsAllowed;

    /**
     * @var int
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $maximumAttendeeCapacity;

    /**
     * People and organizations involved in event organization.
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventOrganizer",
     *     mappedBy="event"
     * )
     */
    protected $eventOrganizers;

    /**
     * People and organizations who attend at the event.
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventAttendee",
     *     mappedBy="event"
     * )
     */
    protected $eventAttendees;

    /**
     * Type of this event.
     * @var EventType|null $eventType
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventType",
     *     inversedBy="events",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="type_id", referencedColumnName="id")
     */
    private $eventType;

    /**
     * Event constructor.
     *
     * @param Nameable|null  $nameable
     * @param Event|null     $superEvent
     * @param Place|null     $location
     * @param EventType|null $type
     * @param bool|null      $registrationRequired
     * @param bool|null      $registrationsAllowed
     * @param int|null       $maximumAttendeeCapacity
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $superEvent = null,
        ?Place $location = null,
        ?EventType $type = null,
        ?bool $registrationRequired = null,
        ?bool $registrationsAllowed = null,
        ?int $maximumAttendeeCapacity = null
    ) {
        $this->subEvents = new ArrayCollection();
        $this->eventAttendees = new ArrayCollection();
        $this->eventOrganizers = new ArrayCollection();
        $this->setEventType($type);
        $this->setFieldsFromNameable($nameable);
        $this->setSuperEvent($superEvent);
        $this->setLocation($location);
        $this->setRegistrationRequired($registrationRequired);
        $this->setRegistrationsAllowed($registrationsAllowed);
        $this->setMaximumAttendeeCapacity($maximumAttendeeCapacity);
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

    /**
     * @return bool|null
     */
    final public function isRegistrationRequired(): ?bool
    {
        return $this->registrationRequired;
    }

    /**
     * @param bool|null $registrationRequired
     */
    final public function setRegistrationRequired(?bool $registrationRequired): void
    {
        $this->registrationRequired = $registrationRequired;
    }

    /**
     * @return bool|null
     */
    final public function isRegistrationsAllowed(): ?bool
    {
        return $this->registrationsAllowed;
    }

    /**
     * @param bool|null $registrationsAllowed
     */
    final public function setRegistrationsAllowed(?bool $registrationsAllowed): void
    {
        $this->registrationsAllowed = $registrationsAllowed;
    }

    /**
     * @return Collection
     */
    final public function getSubEvents(): Collection
    {
        return $this->subEvents;
    }

    /**
     * @return bool
     */
    final public function isRootEvent(): bool
    {
        return $this->superEvent ? false : true;
    }

    /**
     * @param Event|null $event
     */
    final public function addSubEvent(?Event $event): void
    {
        if ($event && !$this->subEvents->contains($event)) {
            $this->subEvents->add($event);
            $event->setSuperEvent($this);
        }
    }

    /**
     * @param Event|null $event
     */
    final public function removeSubEvent(?Event $event): void
    {
        if (!$event) {
            return;
        }
        if ($this->subEvents->removeElement($event)) {
            $event->setSuperEvent(null);
        }
    }

    /**
     * @return Event|null
     */
    final public function getSuperEvent(): ?Event
    {
        return $this->superEvent;
    }

    /**
     * @param Event|null $event
     */
    final public function setSuperEvent(?Event $event): void
    {
        if ($this->superEvent && $event !== $this->superEvent) {
            $this->superEvent->removeSubEvent($this);
        }
        $this->superEvent = $event;
        if ($this->superEvent) {
            $this->superEvent->addSubEvent($this);
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
     * @return Collection|null
     */
    final public function getEventOrganizers(): ?Collection
    {
        return $this->eventOrganizers;
    }

    final public function addEventOrganizer(?EventOrganizer $eventOrganizer): void
    {
        if ($eventOrganizer && !$this->eventOrganizers->contains($eventOrganizer)) {
            $this->eventOrganizers->add($eventOrganizer);
            $eventOrganizer->setEvent($this);
        }
    }

    final public function removeEventOrganizer(?EventOrganizer $eventOrganizer): void
    {
        if (!$eventOrganizer) {
            return;
        }
        if ($this->eventOrganizers->removeElement($eventOrganizer)) {
            $eventOrganizer->setEvent(null);
        }
    }

    final public function addEventAttendee(?EventAttendee $eventAttendee): void
    {
        if ($eventAttendee && !$this->eventAttendees->contains($eventAttendee)) {
            $this->eventAttendees->add($eventAttendee);
            $eventAttendee->setEvent($this);
        }
    }

    final public function removeEventAttendee(?EventAttendee $eventAttendee): void
    {
        if (!$eventAttendee) {
            return;
        }
        if ($this->eventAttendees->removeElement($eventAttendee)) {
            $eventAttendee->setEvent(null);
        }
    }

    final public function getRemainingCapacityPercent(): ?int
    {
        if ($this->getOccupancy() && $this->getRemainingCapacity()) {
            $remaining = $this->getRemainingCapacity() / $this->getOccupancy();

            return $remaining > 0 && $remaining <= 1 ? $remaining : 0;
        }

        return null;
    }

    final public function getOccupancy(): int
    {
        return $this->getEventAttendees() ? $this->getEventAttendees()->count() : 0;
    }

    /**
     * @return Collection|null
     */
    final public function getEventAttendees(): ?Collection
    {
        return $this->eventAttendees;
    }

    final public function getRemainingCapacity(): ?int
    {
        if ($this->getOccupancy() && $this->getMaximumAttendeeCapacity()) {
            $remaining = $this->getMaximumAttendeeCapacity() - $this->getOccupancy();

            return $remaining > 0 ? $remaining : 0;
        }

        return null;
    }

    /**
     * @return int|null
     */
    final public function getMaximumAttendeeCapacity(): ?int
    {
        return $this->maximumAttendeeCapacity;
    }

    /**
     * @param int|null $maximumAttendeeCapacity
     */
    final public function setMaximumAttendeeCapacity(?int $maximumAttendeeCapacity): void
    {
        $this->maximumAttendeeCapacity = $maximumAttendeeCapacity;
    }


}
