<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisAddressBookBundle\Entity\Person;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisAddressBookBundle\Entity\Position;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagConnection;
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
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BankAccountTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Utils\DateTimeUtils;
use function assert;
use function strcmp;

/**
 * Event.
 *
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="Zakjakub\OswisCalendarBundle\Repository\EventRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_event")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_events_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_events_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_put"}, "enable_max_depth"=true}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_delete"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "description",
 *     "note",
 *     "shortName",
 *     "slug"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class Event extends AbstractRevisionContainer
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use DateRangeTrait;
    use ColorTrait;
    use BankAccountTrait;

    /**
     * @var Place|null $location
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisAddressBookBundle\Entity\Place",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     * @MaxDepth(1)
     */
    protected ?Place $location = null;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventFlagNewConnection",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected ?Collection $eventFlagConnections = null;

    /**
     * Parent event (if this is not top level event).
     * @var Event|null $superEvent
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="subEvents",
     *     fetch="EAGER"
     * )
     * @MaxDepth(2)
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $superEvent = null;

    /**
     * Sub events.
     * @var Collection|null $subEvents
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     mappedBy="superEvent",
     *     fetch="EAGER"
     * )
     * @MaxDepth(2)
     */
    protected $subEvents = null;

    /**
     * People and organizations who attend at the event.
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantRevision",
     *     cascade={"all"},
     *     orphanRemoval=true,
     *     mappedBy="event"
     * )
     * @MaxDepth(2)
     */
    protected $eventParticipantRevisions = null;

    /**
     * People and organizations who attend at the event.
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     cascade={"all"},
     *     orphanRemoval=true,
     *     mappedBy="event"
     * )
     * @MaxDepth(2)
     */
    protected ?Collection $eventParticipants = null;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventCapacity",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventCapacities = null;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventPrice",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventPrices = null;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRegistrationRange",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventRegistrationRanges = null;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantTypeInEventConnection",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantTypeInEventConnections = null;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantFlagInEventConnections = null;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRevision",
     *     mappedBy="container",
     *     cascade={"all"},
     *     orphanRemoval=true,
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $revisions = null;

    /**
     * @var AbstractRevision|null
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRevision")
     * @Doctrine\ORM\Mapping\JoinColumn(name="active_revision_id", referencedColumnName="id")
     */
    protected ?AbstractRevision $activeRevision = null;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventWebContent",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected $eventWebContents = null;

    /**
     * Type of this event.
     * @var EventType|null $eventType
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventType",
     *     inversedBy="events",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="type_id", referencedColumnName="id")
     */
    private ?EventType $eventType = null;

    /**
     * @var EventSeries|null $eventSeries
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries",
     *     inversedBy="events"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="event_series_id", referencedColumnName="id")
     * @MaxDepth(1)
     */
    private ?EventSeries $eventSeries = null;

    /**
     * @var bool|null
     * @ORM\Column(type="boolean", nullable=true)
     */
    private ?bool $priceRecursiveFromParent = null;

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
     * @param bool|null        $priceRecursiveFromParent
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $superEvent = null,
        ?Place $location = null,
        ?EventType $eventType = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null,
        ?EventSeries $eventSeries = null,
        ?bool $priceRecursiveFromParent = null
    ) {
        $this->revisions = new ArrayCollection();
        $this->subEvents = new ArrayCollection();
        $this->eventParticipantRevisions = new ArrayCollection();
        $this->eventParticipants = new ArrayCollection();
        $this->eventPrices = new ArrayCollection();
        $this->eventCapacities = new ArrayCollection();
        $this->eventRegistrationRanges = new ArrayCollection();
        $this->eventParticipantTypeInEventConnections = new ArrayCollection();
        $this->eventParticipantFlagInEventConnections = new ArrayCollection();
        $this->eventWebContents = new ArrayCollection();
        $this->setEventType($eventType);
        $this->setSuperEvent($superEvent);
        $this->setEventSeries($eventSeries);
        $this->setPriceRecursiveFromParent($priceRecursiveFromParent);
        $this->addRevision(new EventRevision($nameable, $location, $startDateTime, $endDateTime));
    }

    /**
     * @param bool $priceRecursiveFromParent
     */
    final public function setPriceRecursiveFromParent(?bool $priceRecursiveFromParent): void
    {
        $this->priceRecursiveFromParent = $priceRecursiveFromParent;
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

    final public function destroyRevisions(): void
    {
        try {
            $this->setFieldsFromNameable($this->getRevisionByDate()->getNameable());
            $this->setStartDateTime($this->getRevisionByDate()->getStartDateTime());
            $this->setEndDateTime($this->getRevisionByDate()->getEndDateTime());
            $this->setColor($this->getRevisionByDate()->getColor());
            $this->setBankAccountNumber($this->getRevisionByDate()->getBankAccountNumber());
            $this->setBankAccountBank($this->getRevisionByDate()->getBankAccountBank());
            $this->setLocation($this->getRevisionByDate()->getLocation());
            foreach ($this->getRevisionByDate()->getEventFlagConnections() as $eventFlagConnection) {
                assert($eventFlagConnection instanceof EventFlagConnection);
                $this->addEventFlagConnection(
                    new EventFlagNewConnection($eventFlagConnection->getEventFlag(), null, $eventFlagConnection->getTextValue())
                );
            }
            foreach ($this->getRevisions() as $revision) {
                assert($revision instanceof EventRevision);
                $this->removeRevision($revision);
                foreach ($revision->getEventFlagConnections() as $eventFlagConnection) {
                    $revision->removeEventFlagConnection($eventFlagConnection);
                }
            }
            $this->setActiveRevision(null);
        } catch (RevisionMissingException $e) {
        }
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

    final public function addEventFlagConnection(?EventFlagNewConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && !$this->eventFlagConnections->contains($eventContactFlagConnection)) {
            $this->eventFlagConnections->add($eventContactFlagConnection);
            $eventContactFlagConnection->setEvent($this);
        }
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
     * @return Collection
     */
    final public function getEventParticipantTypeInEventConnections(): Collection
    {
        return $this->eventParticipantTypeInEventConnections;
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
     * @param EventParticipantRevision|null $eventParticipantRevision
     *
     * @throws EventCapacityExceededException
     */
    final public function addEventParticipantRevision(?EventParticipantRevision $eventParticipantRevision): void
    {
        if (!$this->eventParticipantRevisions) {
            $this->eventParticipantRevisions = new ArrayCollection();
        }
        if ($eventParticipantRevision && !$this->eventParticipantRevisions->contains($eventParticipantRevision)) {
            // Check capacity.
            assert($eventParticipantRevision instanceof EventParticipantRevision);
            $eventParticipant = $eventParticipantRevision->getContainer();
            assert($eventParticipant instanceof EventParticipant);
            $eventParticipantType = $eventParticipant->getEventParticipantType();
            if ($this->getRemainingCapacity($eventParticipantType) === 0) {
                throw new EventCapacityExceededException();
            }
            $this->eventParticipantRevisions->add($eventParticipantRevision);
            $eventParticipantRevision->setEvent($this);
        }
    }

    /**
     * @param EventParticipantType|null $eventParticipantType
     *
     * @return int|null
     */
    final public function getRemainingCapacity(
        ?EventParticipantType $eventParticipantType = null
    ): ?int {
        if ($this->getMaximumCapacity() === null) {
            return -1;
        }
        $occupancy = $this->getOccupancy($eventParticipantType);
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

    /**
     * @param EventParticipantType|null $eventParticipantType
     * @param int|null                  $recursiveDepth
     * @param bool|null                 $includeDeleted
     * @param bool|null                 $includeNotActivatedUsers
     *
     * @return int
     */
    final public function getOccupancy(
        ?EventParticipantType $eventParticipantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivatedUsers = true,
        ?int $recursiveDepth = null
    ): int {
        return $this->getActiveEventParticipants(
            $eventParticipantType,
            $includeDeleted,
            $includeNotActivatedUsers,
            $recursiveDepth
        )->count();
    }

    final public function getActiveEventParticipants(
        ?EventParticipantType $eventParticipantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivatedUsers = true,
        ?int $recursiveDepth = 1
    ): Collection {
        /// TODO: Duplicities!!!
        $eventParticipants = $this->getEventParticipantsByType(
            $eventParticipantType,
            $includeDeleted,
            $includeNotActivatedUsers
        );
        if ($recursiveDepth > 0) {
            foreach ($this->getSubEvents() as $subEvent) {
                assert($subEvent instanceof self);
                $subEventParticipants = $subEvent->getActiveEventParticipants(
                    $eventParticipantType,
                    $includeDeleted,
                    $includeNotActivatedUsers,
                    $recursiveDepth - 1
                );
                foreach ($subEventParticipants as $newEventParticipant) {
                    if (!$eventParticipants->contains($newEventParticipant)) {
                        $eventParticipants->add($newEventParticipant);
                    }
                }
            }
        }
        $eventParticipantsArray = $eventParticipants->toArray();
        self::sortEventParticipants($eventParticipantsArray);

        return new ArrayCollection($eventParticipantsArray);
    }

    final public function getEventParticipantsByType(
        ?EventParticipantType $eventParticipantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true
    ): Collection {
        if ($eventParticipantType) {
            $eventParticipants = $this->getEventParticipants($includeDeleted, $includeNotActivated)->filter(
                fn(EventParticipant $eventParticipant) => !$eventParticipant->getEventParticipantType() ? false : $eventParticipantType->getId() === $eventParticipant->getEventParticipantType(
                    )->getId()
            )->toArray();
        } else {
            $eventParticipants = $this->getEventParticipants($includeDeleted, $includeNotActivated)->toArray();
        }
        self::sortEventParticipants($eventParticipants);

        return new ArrayCollection($eventParticipants);
    }

    final public function getEventParticipants(
        ?bool $includeDeleted = false,
        ?bool $includeNotActivatedUsers = true
    ): Collection {
        $eventParticipantsArray = $this->eventParticipants->filter(
            static function (EventParticipant $eventParticipant) use ($includeDeleted, $includeNotActivatedUsers) {
                if ($includeDeleted && $includeNotActivatedUsers) {
                    return true;
                }
                if (!$includeDeleted && $eventParticipant->isDeleted()) {
                    return false;
                }
                if (!$includeNotActivatedUsers) {
                    $person = $eventParticipant->getContact();
                    assert($person instanceof Person);
                    if ($person instanceof Person && $person->getAppUser() && !$person->getAppUser()->getAccountActivationDateTime()) {
                        return false;
                    }
                }

                return true;
            }
        )->toArray();
        self::sortEventParticipants($eventParticipantsArray);

        return new ArrayCollection($eventParticipantsArray);
    }

    final public static function sortEventParticipants(array &$eventParticipants): void
    {
        usort(
            $eventParticipants,
            static function (EventParticipant $arg1, EventParticipant $arg2) {
                if (!$arg1->getContact() || !$arg2->getContact()) {
                    $cmpResult = 0;
                } else {
                    $cmpResult = strcmp($arg1->getContact()->getSortableContactName(), $arg2->getContact()->getSortableContactName());
                }

                return $cmpResult === 0 ? AbstractRevision::cmpId($arg2->getId(), $arg1->getId()) : $cmpResult;
            }
        );
    }

    /**
     * @return Collection
     */
    final public function getSubEvents(): Collection
    {
        return $this->subEvents ?? new ArrayCollection();
    }

    /**
     * @param EventParticipant|null $eventParticipant
     *
     * @param bool|null             $force
     *
     * @throws EventCapacityExceededException
     */
    final public function addEventParticipant(
        ?EventParticipant $eventParticipant,
        ?bool $force = false
    ): void {
        if (!$this->eventParticipants) {
            $this->eventParticipants = new ArrayCollection();
        }
        if ($eventParticipant && !$this->eventParticipants->contains($eventParticipant)) {
            // Check capacity.
            $eventParticipantType = $eventParticipant->getEventParticipantType();
            if (!$force && $this->getRemainingCapacity($eventParticipantType) === 0) {
                throw new EventCapacityExceededException();
            }
            $this->eventParticipants->add($eventParticipant);
            $eventParticipant->setEvent($this);
        }
    }

    /**
     * @param EventParticipant|null $eventParticipant
     *
     * @throws EventCapacityExceededException
     */
    final public function removeEventParticipant(?EventParticipant $eventParticipant): void
    {
        if (!$eventParticipant) {
            return;
        }
        if ($this->eventParticipants->removeElement($eventParticipant)) {
            $eventParticipant->setEvent(null);
        }
    }

    /**
     * @param EventParticipantRevision|null $eventParticipantRevision
     *
     * @throws EventCapacityExceededException
     */
    final public function removeEventParticipantRevision(?EventParticipantRevision $eventParticipantRevision): void
    {
        if (!$eventParticipantRevision) {
            return;
        }
        if ($this->eventParticipantRevisions->removeElement($eventParticipantRevision)) {
            $eventParticipantRevision->setEvent(null);
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
     *
     * @return bool
     */
    final public function containsEventParticipantContact(
        AbstractContact $contact,
        EventParticipantType $eventParticipantType = null
    ): bool {
        return $this->getEventParticipantsByType($eventParticipantType)->exists(
            fn(EventParticipant $participant) => $participant->getContact() && $contact->getId() === $participant->getContact()->getId()
        );
    }

    /**
     * @param AppUser                   $appUser
     * @param EventParticipantType|null $eventParticipantType
     *
     * @return bool
     */
    final public function containsEventParticipantAppUser(
        AppUser $appUser,
        ?EventParticipantType $eventParticipantType = null
    ): bool {
        return $this->getEventParticipantsByType($eventParticipantType)->exists(
            static function (EventParticipant $eventParticipant) use ($appUser) {
                if ($eventParticipant->getContact()) {
                    return false;
                }
                $participantAppUser = $eventParticipant->getContact()->getAppUser();
                assert($participantAppUser instanceof AppUser);

                return $participantAppUser && $appUser->getId() === $participantAppUser->getId();
            }
        );
    }

    /**
     * @param Person $person
     *
     * @return bool
     */
    final public function containsEventParticipantPerson(Person $person): bool
    {
        foreach ($this->getActiveEventParticipants() as $eventParticipant) {
            assert($eventParticipant instanceof EventParticipant);
            $containedPerson = $eventParticipant->getContact();
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

    final public function getActiveEventParticipantsAmount(?EventParticipantType $eventParticipantType = null): int
    {
        return $this->getEventParticipantsByType($eventParticipantType)->count();
    }

    final public function getPrice(EventParticipantType $eventParticipantType): int
    {
        if ($this->getPriceOfEvent($eventParticipantType) !== null) {
            return $this->getPriceOfEvent($eventParticipantType);
        }

        return $this->isPriceRecursiveFromParent() && $this->getSuperEvent() ? $this->getSuperEvent()->getPrice($eventParticipantType) : 0;
    }

    final public function getPriceOfEvent(EventParticipantType $eventParticipantType): ?int
    {
        $hasPrice = false;
        $price = 0;
        foreach ($this->getEventPrices() as $eventPrice) {
            assert($eventPrice instanceof EventPrice);
            if ($eventPrice->isApplicableForEventParticipantType($eventParticipantType)) {
                $price += $eventPrice->getNumericValue();
                $hasPrice = true;
            }
        }
        if (!$hasPrice) {
            return null;
        }

        return $price <= 0 ? 0 : $price;
    }

    /**
     * @return Collection
     */
    final public function getEventPrices(): Collection
    {
        return $this->eventPrices ?? new ArrayCollection();
    }

    /**
     * @return bool
     */
    final public function isPriceRecursiveFromParent(): bool
    {
        return $this->priceRecursiveFromParent ?? false;
    }

    final public function getDeposit(EventParticipantType $eventParticipantType): int
    {
        if ($this->getDepositOfEvent($eventParticipantType) !== null) {
            return $this->getDepositOfEvent($eventParticipantType);
        }

        return $this->isPriceRecursiveFromParent() && $this->getSuperEvent() ? $this->getSuperEvent()->getDeposit($eventParticipantType) : 0;
    }

    final public function getDepositOfEvent(EventParticipantType $eventParticipantType): ?int
    {
        $hasDeposit = false;
        $depositValue = 0;
        foreach ($this->getEventPrices() as $eventPrice) {
            assert($eventPrice instanceof EventPrice);
            if ($eventPrice->isApplicableForEventParticipantType($eventParticipantType)) {
                $depositValue += $eventPrice->getDepositValue();
                $hasDeposit = true;
            }
        }
        if (!$hasDeposit) {
            return null;
        }

        return $depositValue <= 0 ? 0 : $depositValue;
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
        return $this->eventWebContents ?? new ArrayCollection();
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

    final public function getOrganizer(): ?AbstractContact
    {
        return $this->getEventParticipantsByTypeOfType(EventParticipantType::TYPE_ORGANIZER)->first();
    }

    final public function getEventParticipantsByTypeOfType(
        ?string $eventParticipantTypeOfType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        int $recursiveDepth = 0
    ): Collection {
        if ($eventParticipantTypeOfType) {
            if ($recursiveDepth && $recursiveDepth > 0) {
                $eventParticipants = $this->getActiveEventParticipants(
                    null,
                    $includeDeleted,
                    $includeNotActivated,
                    $recursiveDepth
                );
            } else {
                $eventParticipants = $this->getEventParticipants($includeDeleted, $includeNotActivated);
            }
            $eventParticipants = $eventParticipants->filter(
                fn(EventParticipant $participant) => !$participant->getEventParticipantType() ? false : $eventParticipantTypeOfType === $participant->getEventParticipantType()->getType()
            )->toArray();
        } else {
            $eventParticipants = $this->getEventParticipants($includeDeleted, $includeNotActivated)->toArray();
        }
        self::sortEventParticipants($eventParticipants);

        return new ArrayCollection($eventParticipants);
    }

    /**
     * @param bool|null $recursive Get recursively from parents?
     *
     * @return Place|null
     */
    final public function getLocation(?bool $recursive = false): ?Place
    {
        if ($this->getLocation(false)) {
            return $this->getLocation();
        }

        return $recursive && $this->getSuperEvent() ? $this->getSuperEvent()->getLocation() : null; //// TODO
    }

    /**
     * @param Place|null $event
     */
    final public function setLocation(?Place $event): void
    {
        $this->location = $event;
    }

    final public function getStartDateTimeRecursive(): ?DateTime
    {
        $maxDateTime = new DateTime(DateTimeUtils::MAX_DATE_TIME_STRING);
        $startDateTime = $this->getStartDateTime() ?? $maxDateTime;
        foreach ($this->getSubEvents() as $subEvent) {
            assert($subEvent instanceof self);
            $dateTime = $subEvent->getStartDateTimeRecursive();
            if ($dateTime && $dateTime < $startDateTime) {
                $startDateTime = $dateTime;
            }
        }

        return $startDateTime === $maxDateTime ? null : $startDateTime;
    }

    final public function getEndDateTimeRecursive(): ?DateTime
    {
        $minDateTime = new DateTime(DateTimeUtils::MIN_DATE_TIME_STRING);
        $endDateTime = $this->getEndDateTime() ?? $minDateTime;
        foreach ($this->getSubEvents() as $subEvent) {
            assert($subEvent instanceof self);
            $dateTime = $subEvent->getEndDateTimeRecursive();
            if ($dateTime && $dateTime > $endDateTime) {
                $endDateTime = $dateTime;
            }
        }

        return $endDateTime === $minDateTime ? null : $endDateTime;
    }

    final public function getAllowedFlagsByType(?EventParticipantType $eventParticipantType = null): array
    {
        $flags = [];
        foreach ($this->getEventParticipantFlagInEventConnections($eventParticipantType) as $eventParticipantFlagInEventConnection) {
            assert($eventParticipantFlagInEventConnection instanceof EventParticipantFlagInEventConnection);
            $flag = $eventParticipantFlagInEventConnection->getEventParticipantFlag();
            if ($flag) {
                $flagTypeId = $flag->getEventParticipantFlagType() ? $flag->getEventParticipantFlagType()->getSlug() : '';
                $flags[$flagTypeId][] = $flag;
            }
        }

        return $flags;
    }

    /**
     * @param EventParticipantType|null $eventParticipantType
     * @param EventParticipantFlag|null $eventParticipantFlag
     *
     * @return Collection
     */
    final public function getEventParticipantFlagInEventConnections(
        EventParticipantType $eventParticipantType = null,
        ?EventParticipantFlag $eventParticipantFlag = null
    ): Collection {
        if (!$this->eventParticipantFlagInEventConnections) {
            $this->eventParticipantFlagInEventConnections = new ArrayCollection();
        }
        if (!$eventParticipantType && !$eventParticipantFlag) {
            return $this->eventParticipantFlagInEventConnections ?? new ArrayCollection();
        }

        return $this->eventParticipantFlagInEventConnections->filter(
            static function (EventParticipantFlagInEventConnection $flagConn) use ($eventParticipantType, $eventParticipantFlag) {
                if ($eventParticipantFlag && !($flagConn->getEventParticipantFlag() && $flagConn->getEventParticipantFlag()->getId() === $eventParticipantFlag->getId())) {
                    return false;
                }
                if ($eventParticipantType && !($flagConn->getEventParticipantType() && $flagConn->getEventParticipantType()->getId() === $eventParticipantType->getId())) {
                    return false;
                }

                return true;
            }
        );
    }

    /**
     * True if registrations for some participant type (or any if not specified) is allowed in some datetime (or now if not specified).
     *
     * @param EventParticipantType $eventParticipantType
     * @param DateTime|null        $referenceDateTime
     *
     * @return bool
     * @throws Exception
     */
    final public function isRegistrationsAllowed(?EventParticipantType $eventParticipantType = null, ?DateTime $referenceDateTime = null): bool
    {
        return $this->getEventRegistrationRanges()->filter(
                fn(EventRegistrationRange $registrationRange) => $registrationRange->isApplicable($eventParticipantType, $referenceDateTime)
            )->count() > 0;
    }

    /**
     * @return Collection
     */
    final public function getEventRegistrationRanges(): Collection
    {
        return $this->eventRegistrationRanges ?? new ArrayCollection();
    }

    final public function __toString(): string
    {
        $output = ''.$this->getShortName() ?? ''.$this->getName();
        if ($this->getStartDate() && $this->getEndDate() && $this->getLengthInHours() > 24 && $this->getStartDate()->format('Y') === $this->getEndDate()->format('Y')) {
            $output .= ' ('.$this->getStartDate()->format('d. m.');
            $output .= ' až '.$this->getEndDate()->format('d. m.');
            $output .= ' '.$this->getStartDate()->format('Y').')';
        }

        return ''.$output;
    }

    final public function getEventParticipantFlagConnections(?EventParticipantType $eventParticipantType = null): Collection
    {
        $flagConnections = new ArrayCollection();
        foreach ($this->getActiveEventParticipants($eventParticipantType) as $eventParticipant) {
            assert($eventParticipant instanceof EventParticipant);
            foreach ($eventParticipant->getEventParticipantFlagConnections() as $eventParticipantFlagConnection) {
                assert($eventParticipantFlagConnection instanceof EventParticipantFlagConnection);
                if (!$flagConnections->contains($eventParticipantFlagConnection)) {
                    $flagConnections->add($eventParticipantFlagConnection);
                }
            }
        }

        return $flagConnections;
    }

    final public function getAllowedEventParticipantFlagRemainingAmount(
        ?EventParticipantFlag $eventParticipantFlag,
        ?EventParticipantType $eventParticipantType
    ): int {
        $allowedAmount = $this->getAllowedEventParticipantFlagAmount($eventParticipantFlag, $eventParticipantType);
        $actualAmount = $this->getEventParticipantFlagInEventConnections($eventParticipantType, $eventParticipantFlag)->count();
        $result = $allowedAmount - $actualAmount;

        return $result < 0 ? 0 : $result;
    }

    final public function getAllowedEventParticipantFlagAmount(
        ?EventParticipantFlag $eventParticipantFlag,
        ?EventParticipantType $eventParticipantType
    ): int {
        $allowedAmount = 0;
        foreach ($this->getEventParticipantFlagInEventConnections($eventParticipantType, $eventParticipantFlag) as $flagInEventConnection) {
            assert($flagInEventConnection instanceof EventParticipantFlagInEventConnection);
            $allowedAmount += $flagInEventConnection->getActive() ? $flagInEventConnection->getMaxAmountInEvent() : 0;
        }

        return $allowedAmount;
    }

    /**
     * Array of eventParticipants aggregated by flags (and aggregated by flagTypes).
     *
     * array[flagTypeSlug]['flagType']
     * array[flagTypeSlug]['flags'][flagSlug]['flag']
     * array[flagTypeSlug]['flags'][flagSlug]['eventParticipants']
     *
     * @param EventParticipantType|null $eventParticipantType
     * @param bool|null                 $includeDeleted
     * @param bool|null                 $includeNotActivatedUsers
     * @param int|null                  $recursiveDepth Default is 1 for root events, 0 for others.
     *
     * @return array
     */
    final public function getActiveEventParticipantsAggregatedByFlags(
        ?EventParticipantType $eventParticipantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivatedUsers = true,
        ?int $recursiveDepth = null
    ): array {
        if (null === $recursiveDepth) {
            $recursiveDepth = $this->getSuperEvent() ? 0 : 1;
        }
        $output = [];
        $eventParticipants = $this->getActiveEventParticipants($eventParticipantType, $includeDeleted, $includeNotActivatedUsers, $recursiveDepth);
        if ($eventParticipantType) {
            foreach ($eventParticipants as $eventParticipant) {
                assert($eventParticipant instanceof EventParticipant);
                foreach ($eventParticipant->getEventParticipantFlagConnections() as $eventParticipantFlagInEventConnection) {
                    assert($eventParticipantFlagInEventConnection instanceof EventParticipantFlagInEventConnection);
                    $flag = $eventParticipantFlagInEventConnection->getEventParticipantFlag();
                    if ($flag) {
                        $flagType = $flag->getEventParticipantFlagType();
                        $flagTypeSlug = $flagType ? $flagType->getSlug() : '';
                        $flagSlug = $flag->getSlug() ?? '';
                        $output[$flagTypeSlug]['flags'][$flagSlug]['eventParticipants'][] = $eventParticipant;
                        if (!isset($output[$flagTypeSlug]['flagType']) || $output[$flagTypeSlug]['flagType'] !== $flagType) {
                            $output[$flagTypeSlug]['flagType'] = $flagType;
                        }
                        if (!isset($output[$flagTypeSlug]['flags'][$flagSlug]['flag']) || $output[$flagTypeSlug]['flags'][$flagSlug]['flag'] !== $flag) {
                            $output[$flagTypeSlug]['flags'][$flagSlug]['flag'] = $flag;
                        }
                    }
                }
            }
        } else {
            foreach ($eventParticipants as $eventParticipant) {
                assert($eventParticipant instanceof EventParticipant);
                $eventParticipantType = $eventParticipant->getEventParticipantType();
                $eventParticipantTypeSlug = $eventParticipantType->getSlug();
                $eventParticipantTypeArray = [
                    'id'        => $eventParticipantType->getId(),
                    'name'      => $eventParticipantType->getName(),
                    'shortName' => $eventParticipantType->getShortName(),
                ];
                foreach ($eventParticipant->getEventParticipantFlagConnections() as $eventParticipantFlagInEventConnection) {
                    assert($eventParticipantFlagInEventConnection instanceof EventParticipantFlagInEventConnection);
                    $flag = $eventParticipantFlagInEventConnection->getEventParticipantFlag();
                    if ($flag) {
                        $flagType = $flag->getEventParticipantFlagType();
                        $flagTypeSlug = $flagType ? $flagType->getSlug() : '';
                        $flagArray = [
                            'id'        => $flag->getId(),
                            'slug'      => $flag->getSlug(),
                            'name'      => $flag->getName(),
                            'shortName' => $flag->getShortName(),
                            'color'     => $flag->getColor(),
                        ];
                        $flagTypeArray = [
                            'id'        => $flagType->getId(),
                            'slug'      => $flagType->getSlug(),
                            'name'      => $flagType->getName(),
                            'shortName' => $flagType->getShortName(),
                        ];
                        $flagSlug = $flag->getSlug() ?? '';
                        $output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flags'][$flagSlug]['eventParticipants'][] = $eventParticipant;
                        if (isset($output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flags'][$flagSlug]['eventParticipantsCount']) && $output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flags'][$flagSlug]['eventParticipantsCount'] > 0) {
                            $output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flags'][$flagSlug]['eventParticipantsCount']++;
                        } else {
                            $output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flags'][$flagSlug]['eventParticipantsCount'] = 1;
                        }
                        if (!isset($output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flagType']) || $output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flagType'] !== $flagTypeArray) {
                            $output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flagType'] = $flagTypeArray;
                        }
                        if (!isset($output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flags'][$flagSlug]['flag']) || $output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flags'][$flagSlug]['flag'] !== $flagArray) {
                            $output[$eventParticipantTypeSlug]['flagTypes'][$flagTypeSlug]['flags'][$flagSlug]['flag'] = $flagArray;
                        }
                        if (!isset($output[$eventParticipantTypeSlug]['eventParticipantType']) || $output[$eventParticipantTypeSlug]['eventParticipantType'] !== $eventParticipantTypeArray) {
                            $output[$eventParticipantTypeSlug]['eventParticipantType'] = $eventParticipantTypeArray;
                        }
                    }
                }
            }
        }

        return $output;
    }

    /**
     * Array of eventParticipants aggregated by flags (and aggregated by flagTypes).
     *
     * array[schoolSlug]['school']
     * array[schoolSlug]['eventParticipants'][]
     *
     * @param EventParticipantType|null $eventParticipantType
     * @param bool|null                 $includeDeleted
     * @param bool|null                 $includeNotActivatedUsers
     * @param int|null                  $recursiveDepth Default is 1 for root events, 0 for others.
     *
     * @return array
     */
    final public function getActiveEventParticipantsAggregatedBySchool(
        ?EventParticipantType $eventParticipantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivatedUsers = false,
        ?int $recursiveDepth = null
    ): array {
        if (null === $recursiveDepth) {
            $recursiveDepth = $this->getSuperEvent() ? 0 : 1;
        }
        $output = [];
        $eventParticipants = $this->getActiveEventParticipants($eventParticipantType, $includeDeleted, $includeNotActivatedUsers, $recursiveDepth);
        if ($eventParticipantType) {
            foreach ($eventParticipants as $eventParticipant) {
                assert($eventParticipant instanceof EventParticipant);
                $person = $eventParticipant->getContact();
                if ($person instanceof Person) { // Fix for organizations!
                    foreach ($person->getStudies() as $study) {
                        assert($study instanceof Position);
                        $school = $study->getOrganization();
                        $schoolSlug = $school ? $school->getSlug() : '';
                        $output[$schoolSlug]['eventParticipants'][] = $eventParticipant;
                        if (!isset($output[$schoolSlug]['school']) || $output[$schoolSlug]['school'] !== $school) {
                            $output[$schoolSlug]['school'] = $school;
                        }
                    }
                }
            }
        } else {
            foreach ($eventParticipants as $eventParticipant) {
                assert($eventParticipant instanceof EventParticipant);
                $eventParticipantType = $eventParticipant->getEventParticipantType();
                $eventParticipantTypeSlug = $eventParticipantType->getSlug();
                $person = $eventParticipant->getContact();
                if ($person instanceof Person) { // Fix for organizations!
                    foreach ($person->getStudies() as $study) {
                        assert($study instanceof Position);
                        $school = $study->getOrganization();
                        $schoolSlug = $school ? $school->getSlug() : '';
                        $output[$eventParticipantTypeSlug]['schools'][$schoolSlug]['eventParticipants'][] = $eventParticipant;
                        if (!isset($output[$eventParticipantTypeSlug]['schools'][$schoolSlug]['school']) || $output[$eventParticipantTypeSlug]['schools'][$schoolSlug]['school'] !== $school) {
                            $output[$eventParticipantTypeSlug]['schools'][$schoolSlug]['school'] = $school;
                        }
                        if (!isset($output[$eventParticipantTypeSlug]['eventParticipantType']) || $output[$eventParticipantTypeSlug]['eventParticipantType'] !== $eventParticipantType) {
                            $output[$eventParticipantTypeSlug]['eventParticipantType'] = $eventParticipantType;
                        }
                    }
                }
            }
        }

        return $output;
    }

    /**
     * @return Collection|null
     */
    final public function getEventFlagConnections(): ?Collection
    {
        return $this->eventFlagConnections;
    }

    final public function removeEventFlagConnection(?EventFlagNewConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && $this->eventFlagConnections->removeElement($eventContactFlagConnection)) {
            $eventContactFlagConnection->setEvent(null);
        }
    }

}
