<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use InvalidArgumentException;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisAddressBookBundle\Entity\Person;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantRevision;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantTypeInEventConnection;
use Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Entity\AppUser;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Exceptions\RevisionMissingException;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeContainerTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicContainerTrait;
use Zakjakub\OswisCoreBundle\Utils\DateTimeUtils;
use function assert;

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(name="calendar_event")
 */
class Event extends AbstractRevisionContainer
{

    use BasicEntityTrait;
    use NameableBasicContainerTrait;
    use DateRangeContainerTrait;

    /**
     * Parent event (if this is not top level event).
     * @var Event|null $superEvent
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
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
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     mappedBy="superEvent",
     *     fetch="EAGER"
     * )
     */
    protected $subEvents;

    /**
     * People and organizations who attend at the event.
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantRevision",
     *     cascade={"all"},
     *     orphanRemoval=true,
     *     mappedBy="event"
     * )
     */
    protected $eventParticipantRevisions;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventCapacity",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventCapacities;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventPrice",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventPrices;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRegistrationRange",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventRegistrationRanges;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantTypeInEventConnection",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantTypeInEventConnections;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantFlagInEventConnections;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRevision",
     *     mappedBy="container",
     *     cascade={"all"},
     *     orphanRemoval=true,
     *     fetch="EAGER"
     * )
     */
    protected $revisions;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventWebContent",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventWebContents;

    /**
     * @var EventSeries|null $eventType
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries",
     *     inversedBy="events",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="event_series_id", referencedColumnName="id")
     */
    private $eventSeries;

    /**
     * Event constructor.
     *
     * @param Nameable|null    $nameable
     * @param Event|null       $superEvent
     * @param Place|null       $location
     * @param EventType|null   $eventType
     * @param DateTime|null    $startDateTime
     * @param DateTime|null    $endDateTime
     * @param EventSeries|null $eventSeries
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $superEvent = null,
        ?Place $location = null,
        ?EventType $eventType = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null,
        ?EventSeries $eventSeries = null
    ) {
        $this->subEvents = new ArrayCollection();
        $this->eventParticipantRevisions = new ArrayCollection();
        $this->eventPrices = new ArrayCollection();
        $this->eventCapacities = new ArrayCollection();
        $this->eventRegistrationRanges = new ArrayCollection();
        $this->eventParticipantTypeInEventConnections = new ArrayCollection();
        $this->revisions = new ArrayCollection();
        $this->revisions->add(new EventRevision($nameable, $location, $eventType, $startDateTime, $endDateTime));
        $this->setSuperEvent($superEvent);
        $this->setEventSeries($eventSeries);
    }

    /**
     * @return string
     */
    public static function getRevisionClassName(): string
    {
        return EventRev::class;
    }

    /**
     * @param AbstractRevision|null $revision
     */
    public static function checkRevision(?AbstractRevision $revision): void
    {
        assert($revision instanceof EventRevision);
    }

    /**
     * @return Collection
     */
    final public function getEventRegistrationRanges(): Collection
    {
        return $this->eventRegistrationRanges;
    }

    /**
     * @return Collection
     */
    final public function getEventParticipantTypeInEventConnections(): Collection
    {
        return $this->eventParticipantTypeInEventConnections;
    }

    /**
     * @return Collection
     */
    final public function getEventParticipantFlagInEventConnections(): Collection
    {
        return $this->eventParticipantFlagInEventConnections;
    }

    /**
     * @param DateTime|null $dateTime
     *
     * @return EventRevision
     * @throws RevisionMissingException
     */
    final public function getRevisionByDate(?DateTime $dateTime = null): EventRevision
    {
        $revision = $this->getRevision($dateTime);
        assert($revision instanceof EventRevision);

        return $revision;
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
        return $this->getSuperEvent() ? false : true;
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
     * @param EventParticipant $eventParticipant
     *
     * @throws EventCapacityExceededException
     * @throws RevisionMissingException
     */
    final public function addEventParticipant(EventParticipant $eventParticipant): void
    {
        $this->addEventParticipantRevision($eventParticipant->getRevisionByDate());
    }

    /**
     * @param EventParticipantRevision|null $eventParticipantRevision
     *
     * @throws EventCapacityExceededException
     */
    final public function addEventParticipantRevision(?EventParticipantRevision $eventParticipantRevision): void
    {
        if ($eventParticipantRevision && !$this->eventParticipantRevisions->contains($eventParticipantRevision)) {
            // Check capacity.
            $eventParticipant = $eventParticipantRevision->getContainer();
            assert($eventParticipant instanceof EventParticipant);
            $eventParticipantType = $eventParticipant->getEventParticipantType();
            if ($this->getOccupancy(null, $eventParticipantType) === 0) {
                throw new EventCapacityExceededException();
            }
            $this->eventParticipantRevisions->add($eventParticipantRevision);
            $eventParticipantRevision->setEvent($this);
        }
    }

    /**
     * @param DateTime|null             $referenceDateTime
     * @param EventParticipantType|null $eventParticipantType
     *
     * @return int
     */
    final public function getOccupancy(
        ?DateTime $referenceDateTime = null,
        ?EventParticipantType $eventParticipantType = null
    ): int {
        return $this->getActiveEventParticipantsByType($eventParticipantType, $referenceDateTime)->count();
    }

    final public function getActiveEventParticipantsByType(
        ?EventParticipantType $eventParticipantType = null,
        ?DateTime $referenceDateTime = null
    ): Collection {
        if ($eventParticipantType) {
            return $this->getActiveEventParticipants($referenceDateTime)->filter(
                static function (EventParticipant $eventParticipant) use ($eventParticipantType) {
                    if (!$eventParticipant->getEventParticipantType()) {
                        return false;
                    }

                    return $eventParticipantType->getId() === $eventParticipant->getEventParticipantType()->getId();
                }
            );
        }

        return $this->getActiveEventParticipants($referenceDateTime);
    }

    /**
     * Returns EventParticipants whose are active at specified moment (or now, if no referenceDateTime is specified).
     *
     * @param DateTime|null $referenceDateTime
     *
     * @return Collection Active EventParticipants.
     */
    final public function getActiveEventParticipants(?DateTime $referenceDateTime = null): Collection
    {
        return $this->getActiveEventParticipantRevisions($referenceDateTime)->map(
            static function (EventParticipantRevision $eventParticipantRevision) {
                return $eventParticipantRevision->getContainer();
            }
        );
    }

    /**
     * Returns EventParticipantRevisions whose are active at specified moment (or now, if no referenceDateTime is specified).
     *
     * @param DateTime|null $referenceDateTime
     *
     * @return Collection Active EventParticipantRevisions.
     */
    final public function getActiveEventParticipantRevisions(?DateTime $referenceDateTime = null): Collection
    {
        return $this->getEventParticipantRevisions()->filter(
            static function (EventParticipantRevision $eventParticipantRevision) use ($referenceDateTime) {
                return $eventParticipantRevision->isActive($referenceDateTime);
            }
        );
    }

    /**
     * @return Collection
     */
    final public function getEventParticipantRevisions(): Collection
    {
        return $this->eventParticipantRevisions ?? new ArrayCollection();
    }

    /**
     * @param EventParticipant $eventParticipant
     *
     * @throws EventCapacityExceededException
     * @throws RevisionMissingException
     */
    final public function removeEventParticipant(EventParticipant $eventParticipant): void
    {
        $this->removeEventParticipantRevision($eventParticipant->getRevisionByDate());
    }

    /**
     * @param EventParticipantRevision|null $eventContactRevision
     *
     * @throws EventCapacityExceededException
     */
    final public function removeEventParticipantRevision(?EventParticipantRevision $eventContactRevision): void
    {
        if (!$eventContactRevision) {
            return;
        }
        if ($this->eventParticipantRevisions->removeElement($eventContactRevision)) {
            $eventContactRevision->setEvent(null);
        }
    }

    /**
     * @param EventParticipantTypeInEventConnection|null $eventParticipantTypeInEventConnection
     */
    final public function addEventParticipantTypeInEventConnection(?EventParticipantTypeInEventConnection $eventParticipantTypeInEventConnection): void
    {
        if ($eventParticipantTypeInEventConnection && !$this->eventParticipantTypeInEventConnections->contains($eventParticipantTypeInEventConnection)) {
            $this->eventParticipantTypeInEventConnections->add($eventParticipantTypeInEventConnection);
            $eventParticipantTypeInEventConnection->setEvent($this);
        }
    }

    /**
     * @param EventParticipantTypeInEventConnection|null $eventParticipantTypeInEventConnection
     */
    final public function removeEventParticipantTypeInEventConnection(?EventParticipantTypeInEventConnection $eventParticipantTypeInEventConnection): void
    {
        if (!$eventParticipantTypeInEventConnection) {
            return;
        }
        if ($this->eventParticipantTypeInEventConnections->removeElement($eventParticipantTypeInEventConnection)) {
            $eventParticipantTypeInEventConnection->setEvent(null);
        }
    }

    /**
     * @param EventParticipantFlagInEventConnection|null $eventParticipantFlagInEventConnection
     */
    final public function addEventParticipantFlagInEventConnection(?EventParticipantFlagInEventConnection $eventParticipantFlagInEventConnection): void
    {
        if ($eventParticipantFlagInEventConnection && !$this->eventParticipantFlagInEventConnections->contains($eventParticipantFlagInEventConnection)) {
            $this->eventParticipantFlagInEventConnections->add($eventParticipantFlagInEventConnection);
            $eventParticipantFlagInEventConnection->setEvent($this);
        }
    }

    /**
     * @param EventParticipantFlagInEventConnection|null $eventParticipantFlagInEventConnection
     */
    final public function removeEventParticipantFlagInEventConnection(?EventParticipantFlagInEventConnection $eventParticipantFlagInEventConnection): void
    {
        if (!$eventParticipantFlagInEventConnection) {
            return;
        }
        if ($this->eventParticipantFlagInEventConnections->removeElement($eventParticipantFlagInEventConnection)) {
            $eventParticipantFlagInEventConnection->setEvent(null);
        }
    }

    /**
     * @param EventPrice|null $eventContactRevision
     */
    final public function addEventPrice(?EventPrice $eventContactRevision): void
    {
        if ($eventContactRevision && !$this->eventPrices->contains($eventContactRevision)) {
            $this->eventPrices->add($eventContactRevision);
            $eventContactRevision->setEvent($this);
        }
    }

    /**
     * @param EventPrice|null $eventContactRevision
     */
    final public function removeEventPrice(?EventPrice $eventContactRevision): void
    {
        if (!$eventContactRevision) {
            return;
        }
        if ($this->eventPrices->removeElement($eventContactRevision)) {
            $eventContactRevision->setEvent(null);
        }
    }

    /**
     * @param EventCapacity|null $eventContactRevision
     */
    final public function addEventCapacity(?EventCapacity $eventContactRevision): void
    {
        if ($eventContactRevision && !$this->eventCapacities->contains($eventContactRevision)) {
            $this->eventCapacities->add($eventContactRevision);
            $eventContactRevision->setEvent($this);
        }
    }

    /**
     * @param EventCapacity|null $eventContactRevision
     */
    final public function removeEventCapacity(?EventCapacity $eventContactRevision): void
    {
        if (!$eventContactRevision) {
            return;
        }
        if ($this->eventCapacities->removeElement($eventContactRevision)) {
            $eventContactRevision->setEvent(null);
        }
    }

    /**
     * @param EventRegistrationRange|null $eventContactRevision
     */
    final public function addEventRegistrationRange(?EventRegistrationRange $eventContactRevision): void
    {
        if ($eventContactRevision && !$this->eventRegistrationRanges->contains($eventContactRevision)) {
            $this->eventRegistrationRanges->add($eventContactRevision);
            $eventContactRevision->setEvent($this);
        }
    }

    /**
     * @param EventRegistrationRange|null $eventContactRevision
     */
    final public function removeEventRegistrationRange(?EventRegistrationRange $eventContactRevision): void
    {
        if (!$eventContactRevision) {
            return;
        }
        if ($this->eventRegistrationRanges->removeElement($eventContactRevision)) {
            $eventContactRevision->setEvent(null);
        }
    }

    /**
     * @param AbstractContact           $contact
     * @param EventParticipantType|null $eventParticipantType
     * @param DateTime|null             $referenceDateTime
     *
     * @return bool
     */
    final public function containsEventParticipantContact(
        AbstractContact $contact,
        EventParticipantType $eventParticipantType = null,
        ?DateTime $referenceDateTime = null
    ): bool {
        return $this->getActiveEventParticipantsByType($eventParticipantType, $referenceDateTime)->exists(
            static function (EventParticipant $eventParticipant) use ($contact, $referenceDateTime) {
                $participantContact = $eventParticipant->getContact($referenceDateTime);

                return $participantContact && $contact->getId() === $participantContact->getId();
            }
        );
    }

    /**
     * @param AppUser                   $appUser
     *
     * @param EventParticipantType|null $eventParticipantType
     * @param DateTime|null             $referenceDateTime
     *
     * @return bool
     */
    final public function containsEventParticipantAppUser(
        AppUser $appUser,
        ?EventParticipantType $eventParticipantType = null,
        ?DateTime $referenceDateTime = null
    ): bool {
        return $this->getActiveEventParticipantsByType($eventParticipantType, $referenceDateTime)->exists(
            static function (EventParticipant $eventParticipant) use ($appUser, $referenceDateTime) {
                try {
                    /** @noinspection PhpUndefinedMethodInspection */
                    /** @noinspection NullPointerExceptionInspection */
                    $participantAppUser = $eventParticipant->getRevisionByDate($referenceDateTime)->getContact()->getAppUser();
                    assert($participantAppUser instanceof AppUser);

                    return $participantAppUser && $appUser->getId() === $participantAppUser->getId();
                } catch (Exception $e) {
                    return false;
                }
            }
        );
    }

    /**
     * @param Person        $person
     *
     * @param DateTime|null $referenceDateTime
     *
     * @return bool
     * @throws RevisionMissingException
     */
    final public function containsEventParticipantPerson(
        Person $person,
        ?DateTime $referenceDateTime = null
    ): bool {
        foreach ($this->getActiveEventParticipantRevisions($referenceDateTime) as $eventContact) {
            assert($eventContact instanceof EventParticipant);
            $containedPerson = $eventContact->getContact();
            if (!$containedPerson) {
                continue;
            }
            assert($containedPerson instanceof Person);
            if ($containedPerson && $person->getId() === $containedPerson->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param EventParticipantType|null $eventParticipantType
     *
     * @param DateTime|null             $referenceDateTime
     *
     * @return int|null
     */
    final public function getRemainingCapacity(
        ?EventParticipantType $eventParticipantType = null,
        ?DateTime $referenceDateTime = null
    ): ?int {
        if ($this->getMaximumCapacity() === null) {
            return -1;
        }
        $occupancy = $this->getOccupancy($referenceDateTime, $eventParticipantType);
        $maximumCapacity = $this->getMaximumCapacity($eventParticipantType);
        if ($occupancy >= 0 && $maximumCapacity >= 0) {
            $remaining = $maximumCapacity - $occupancy;

            return $remaining > 0 ? $remaining : 0;
        }

        return -1;
    }

    /**
     * @param EventParticipantType|null $eventParticipantType
     *
     * @return int|null
     */
    final public function getMaximumCapacity(
        ?EventParticipantType $eventParticipantType = null
    ): ?int {
        $capacity = -1;
        foreach ($this->getEventCapacities() as $eventCapacity) {
            try {
                assert($eventCapacity instanceof EventCapacity);
                $oneEventParticipantType = $eventCapacity->getEventParticipantType();
                if (!$eventParticipantType || ($oneEventParticipantType && $eventParticipantType->getId() === $oneEventParticipantType->getId())) {
                    $capacity += $eventCapacity->getNumericValue();
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return $capacity;
    }

    /**
     * @return Collection
     */
    final public function getEventCapacities(): Collection
    {
        return $this->eventCapacities ?? new ArrayCollection();
    }

    final public function getActiveEventParticipantsAmount(
        ?EventParticipantType $eventParticipantType = null
    ): int {
        return $this->getActiveEventParticipantsByType($eventParticipantType)->count();
    }

    final public function getEventPriceRecursive(EventParticipantType $eventParticipantType): ?int
    {
        if ($this->getEventPrice($eventParticipantType)) {
            return $this->getEventPrice($eventParticipantType);
        }

        return $this->getSuperEvent() ? $this->getSuperEvent()->getEventPriceRecursive($eventParticipantType) : null;
    }

    final public function getEventPrice(EventParticipantType $eventParticipantType): int
    {
        $price = 0;
        foreach ($this->getEventPrices() as $eventPrice) {
            assert($eventPrice instanceof EventPrice);
            if ($eventPrice->isApplicableForEventParticipantType($eventParticipantType)) {
                $price += $eventPrice->getNumericValue();
            }
        }

        return $price <= 0 ? 0 : $price;
    }

    /**
     * @return Collection
     */
    final public function getEventPrices(): Collection
    {
        return $this->eventPrices;
    }

    final public function getDeposit(EventParticipantType $eventParticipantType): int
    {
        $price = 0;
        foreach ($this->getEventPrices() as $eventPrice) {
            assert($eventPrice instanceof EventPrice);
            if ($eventPrice->isApplicableForEventParticipantType($eventParticipantType)) {
                $price += $eventPrice->getDepo;
            }
        }

        return $price <= 0 ? 0 : $price;
    }

    /**
     * @return EventSeries|null
     */
    final public function getEventSeries(): ?EventSeries
    {
        return $this->eventSeries;
    }

    /**
     * @param EventSeries|null $eventSeries
     */
    final public function setEventSeries(?EventSeries $eventSeries): void
    {
        if ($this->eventSeries && $eventSeries !== $this->eventSeries) {
            $this->eventSeries->removeEvent($this);
        }
        $this->eventSeries = $eventSeries;
        if ($eventSeries && $this->eventSeries !== $eventSeries) {
            $eventSeries->addEvent($this);
        }
    }

    /**
     * @param EventWebContent|null $eventWebContent
     *
     * @throws InvalidArgumentException
     */
    final public function addEventWebContent(?EventWebContent $eventWebContent): void
    {
        $existingOne = null;
        if ($eventWebContent && $eventWebContent->getType()) {
            $existingOne = $this->getEventWebContent($eventWebContent->getType());
        }
        if ($existingOne) {
            $this->removeWebContent($existingOne);
        }
        if ($eventWebContent && !$this->eventWebContents->contains($eventWebContent)) {
            $this->eventWebContents->add($eventWebContent);
            $eventWebContent->setEvent($this);
        }
    }

    /**
     * @param string $type
     *
     * @return EventWebContent|null
     * @throws InvalidArgumentException
     */
    final public function getEventWebContent(string $type = 'html'): ?EventWebContent
    {
        foreach ($this->getEventWebContents() as $eventWebContent) {
            assert($eventWebContent instanceof EventWebContent);
            if ($type === $eventWebContent->getType()) {
                return $eventWebContent;
            }
        }

        return null;
    }

    /**
     * @return Collection|null
     */
    final public function getEventWebContents(): ?Collection
    {
        return $this->eventWebContents;
    }

    /**
     * @param EventWebContent|null $eventWebContent
     *
     * @throws InvalidArgumentException
     */
    final public function removeWebContent(?EventWebContent $eventWebContent): void
    {
        if (!$eventWebContent) {
            return;
        }
        if ($this->eventWebContents->removeElement($eventWebContent)) {
            $eventWebContent->setEvent(null);
        }
    }


    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return Place|null
     * @throws RevisionMissingException
     */
    final public function getLocation(?DateTime $referenceDateTime = null): ?Place
    {
        return $this->getRevisionByDate($referenceDateTime)->getLocation();
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return Place|null
     * @throws RevisionMissingException
     */
    final public function getLocationRecursive(?DateTime $referenceDateTime = null): ?Place
    {
        if ($this->getLocation($referenceDateTime)) {
            return $this->getLocation($referenceDateTime);
        }

        return $this->getSuperEvent() ? $this->getSuperEvent()->getLocationRecursive($referenceDateTime) : null; //// TODO
    }

    final public function getStartDateTimeRecursive(?DateTime $referenceDateTime = null): ?DateTime
    {
        $maxDateTime = new DateTime(DateTimeUtils::MAX_DATE_TIME_STRING);
        $startDateTime = $this->getStartDateTime($referenceDateTime) ?? $maxDateTime;
        foreach ($this->getSubEvents() as $subEvent) {
            assert($subEvent instanceof self);
            $dateTime = $subEvent->getStartDateTimeRecursive($referenceDateTime);
            if ($dateTime && $dateTime < $startDateTime) {
                $startDateTime = $dateTime;
            }
        }

        return $startDateTime === $maxDateTime ? null : $startDateTime;
    }

    final public function getEndDateTimeRecursive(?DateTime $referenceDateTime = null): ?DateTime
    {
        $minDateTime = new DateTime(DateTimeUtils::MIN_DATE_TIME_STRING);
        $endDateTime = $this->getEndDateTime($referenceDateTime) ?? $minDateTime;
        foreach ($this->getSubEvents() as $subEvent) {
            assert($subEvent instanceof self);
            $dateTime = $subEvent->getEndDateTimeRecursive($referenceDateTime);
            if ($dateTime && $dateTime > $endDateTime) {
                $endDateTime = $dateTime;
            }
        }

        return $endDateTime === $minDateTime ? null : $endDateTime;
    }

}
