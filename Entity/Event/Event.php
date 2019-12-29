<?php /** @noinspection MethodShouldBeFinalInspection */
/** @noinspection RedundantDocCommentTagInspection */
/**
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantTypeInEventConnection;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BankAccountTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DeletedTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\EntityPublicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Utils\DateTimeUtils;
use function assert;

/**
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
class Event
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use DateRangeTrait;
    use ColorTrait;
    use BankAccountTrait;
    use DeletedTrait;
    use EntityPublicTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="Zakjakub\OswisAddressBookBundle\Entity\Place", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Place $location = null;

    /**
     * @var Collection<EventFlagNewConnection> $eventFlagConnections
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
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="subEvents",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $superEvent = null;

    /**
     * @var Collection<Event> $subEvents
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     mappedBy="superEvent",
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $subEvents = null;

    /**
     * @var Collection<EventCapacity> $eventCapacities
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventCapacity",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_event_capacity_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_capacity_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $eventCapacities = null;

    /**
     * @var Collection<EventWebContent> $eventWebContents
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventWebContent",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_event_web_content_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_web_content_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $eventWebContents = null;

    /**
     * @var Collection<EventPrice> $eventPrices
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventPrice",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_event_price_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_price_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $eventPrices = null;

    /**
     * @var Collection<EventRegistrationRange> $eventRegistrationRanges
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRegistrationRange",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_event_registration_range_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_registration_range_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $eventRegistrationRanges = null;

    /**
     * @var Collection<EventParticipantTypeInEventConnection> $eventParticipantTypeInEventConnections
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantTypeInEventConnection",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $eventParticipantTypeInEventConnections = null;

    /**
     * @var Collection<EventParticipantFlagInEventConnection> $eventParticipantFlagInEventConnections
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection",
     *     cascade={"all"},
     *     mappedBy="event",
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $eventParticipantFlagInEventConnections = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="type_id", referencedColumnName="id")
     */
    private ?EventType $eventType = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries",
     *     inversedBy="events",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="event_series_id", referencedColumnName="id")
     */
    private ?EventSeries $eventSeries = null;

    /**
     * Indicates if price is relative to parent event.
     * @ORM\Column(type="boolean", nullable=true)
     */
    private ?bool $priceRelative = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?Event $superEvent = null,
        ?Place $location = null,
        ?EventType $eventType = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null,
        ?EventSeries $eventSeries = null,
        ?bool $priceRelative = null,
        ?string $color = null,
        ?string $bankAccountNumber = null,
        ?string $bankAccountBank = null
    ) {
        $this->subEvents = new ArrayCollection();
        $this->eventPrices = new ArrayCollection();
        $this->eventCapacities = new ArrayCollection();
        $this->eventRegistrationRanges = new ArrayCollection();
        $this->eventParticipantTypeInEventConnections = new ArrayCollection();
        $this->eventParticipantFlagInEventConnections = new ArrayCollection();
        $this->setEventType($eventType);
        $this->setSuperEvent($superEvent);
        $this->setEventSeries($eventSeries);
        $this->setPriceRelative($priceRelative);
        $this->setFieldsFromNameable($nameable);
        $this->setLocation($location);
        $this->setStartDateTime($startDateTime);
        $this->setEndDateTime($endDateTime);
        $this->setColor($color);
        $this->setBankAccountNumber($bankAccountNumber);
        $this->setBankAccountBank($bankAccountBank);
    }

    final public function setPriceRelative(?bool $priceRelative): void
    {
        $this->priceRelative = $priceRelative;
    }

    final public function destroyRevisions(): void
    {
    }

    final public function addEventPrice(?EventPrice $eventPrice): void
    {
        if ($eventPrice && !$this->eventPrices->contains($eventPrice)) {
            $this->eventPrices->add($eventPrice);
        }
    }

    final public function addEventCapacity(?EventCapacity $eventCapacity): void
    {
        if ($eventCapacity && !$this->eventCapacities->contains($eventCapacity)) {
            $this->eventCapacities->add($eventCapacity);
        }
    }

    final public function addEventRegistrationRange(?EventRegistrationRange $eventRegistrationRange): void
    {
        if ($eventRegistrationRange && !$this->eventRegistrationRanges->contains($eventRegistrationRange)) {
            $this->eventRegistrationRanges->add($eventRegistrationRange);
        }
    }

    final public function addEventFlagConnection(?EventFlagNewConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && !$this->eventFlagConnections->contains($eventContactFlagConnection)) {
            $this->eventFlagConnections->add($eventContactFlagConnection);
            $eventContactFlagConnection->setEvent($this);
        }
    }

    final public function getEventParticipantTypeInEventConnections(): Collection
    {
        return $this->eventParticipantTypeInEventConnections ?? new ArrayCollection();
    }

    final public function isRootEvent(): bool
    {
        return $this->getSuperEvent() ? false : true;
    }

    final public function getSuperEvent(): ?Event
    {
        return $this->superEvent;
    }

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

    final public function addSubEvent(?Event $event): void
    {
        if ($event && !$this->subEvents->contains($event)) {
            $this->subEvents->add($event);
            $event->setSuperEvent($this);
        }
    }

    final public function removeSubEvent(?Event $event): void
    {
        if ($event && $this->subEvents->removeElement($event)) {
            $event->setSuperEvent(null);
        }
    }

    final public function getMaximumCapacity(?EventParticipantType $participantType = null): ?int
    {
        $capacity = null;
        foreach ($this->getEventCapacities() as $oneCapacity) {
            try {
                assert($oneCapacity instanceof EventCapacity);
                $oneParticipantType = $oneCapacity->getEventParticipantType();
                if (!$participantType || ($oneParticipantType && $participantType->getId() === $oneParticipantType->getId())) {
                    $capacity += $oneCapacity->getNumericValue();
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return $capacity;
    }

    final public function getEventCapacities(): Collection
    {
        return $this->eventCapacities ?? new ArrayCollection();
    }

    final public function addEventParticipantTypeInEventConnection(?EventParticipantTypeInEventConnection $participantTypeInEventConnection): void
    {
        if ($participantTypeInEventConnection && !$this->eventParticipantTypeInEventConnections->contains($participantTypeInEventConnection)) {
            $this->eventParticipantTypeInEventConnections->add($participantTypeInEventConnection);
            $participantTypeInEventConnection->setEvent($this);
        }
    }

    final public function removeEventParticipantTypeInEventConnection(?EventParticipantTypeInEventConnection $participantTypeInEventConnection): void
    {
        if ($participantTypeInEventConnection && $this->eventParticipantTypeInEventConnections->removeElement($participantTypeInEventConnection)) {
            $participantTypeInEventConnection->setEvent(null);
        }
    }

    final public function addEventParticipantFlagInEventConnection(?EventParticipantFlagInEventConnection $participantFlagInEventConnection): void
    {
        if ($participantFlagInEventConnection && !$this->eventParticipantFlagInEventConnections->contains($participantFlagInEventConnection)) {
            $this->eventParticipantFlagInEventConnections->add($participantFlagInEventConnection);
            $participantFlagInEventConnection->setEvent($this);
        }
    }

    final public function removeEventParticipantFlagInEventConnection(?EventParticipantFlagInEventConnection $participantFlagInEventConnection): void
    {
        if ($participantFlagInEventConnection && $this->eventParticipantFlagInEventConnections->removeElement($participantFlagInEventConnection)) {
            $participantFlagInEventConnection->setEvent(null);
        }
    }

    final public function removeEventPrice(?EventPrice $eventPrice): void
    {
        if (null !== $eventPrice) {
            $this->eventPrices->removeElement($eventPrice);
        }
    }

    final public function removeEventCapacity(?EventCapacity $eventCapacity): void
    {
        if (null !== $eventCapacity) {
            $this->eventCapacities->removeElement($eventCapacity);
        }
    }

    final public function removeEventRegistrationRange(?EventRegistrationRange $eventRegistrationRange): void
    {
        if (null !== $eventRegistrationRange) {
            $this->eventRegistrationRanges->removeElement($eventRegistrationRange);
        }
    }

    final public function getPrice(EventParticipantType $participantType): ?int
    {
        $total = null;
        foreach ($this->getEventPrices($participantType) as $price) {
            assert($price instanceof EventPrice);
            $total += $price->getNumericValue();
            if ($price->isRelative() && null !== $this->getSuperEvent()) {
                $total += $this->getSuperEvent()->getPrice($participantType);
            }
        }

        return null !== $total && $total <= 0 ? 0 : $total;
    }

    public function getEventPrices(?EventParticipantType $participantType = null): Collection
    {
        if (null !== $participantType) {
            return $this->getEventPrices()->filter(fn(EventPrice $price) => $price->isApplicableForEventParticipantType($participantType));
        }

        return $this->eventPrices ?? new ArrayCollection();
    }

    final public function getDeposit(EventParticipantType $eventParticipantType): int
    {
        if ($this->getDepositOfEvent($eventParticipantType) !== null) {
            return $this->getDepositOfEvent($eventParticipantType);
        }

        return $this->isPriceRelative() && $this->getSuperEvent() ? $this->getSuperEvent()->getDeposit($eventParticipantType) : 0;
    }

    final public function getDepositOfEvent(EventParticipantType $participantType): ?int
    {
        $total = null;
        foreach ($this->getEventPrices($participantType) as $price) {
            assert($price instanceof EventPrice);
            $total += $price->getDepositValue();
            if ($price->isRelative() && null !== $this->getSuperEvent()) {
                $total += $this->getSuperEvent()->getDeposit($participantType);
            }
        }

        return null !== $total && $total <= 0 ? 0 : $total;
    }

    final public function isPriceRelative(): bool
    {
        return $this->priceRelative ?? false;
    }

    final public function addEventWebContent(?EventWebContent $eventWebContent): void
    {
        if (null !== $eventWebContent) {
            return;
        }
        $this->removeEventWebContent($this->getEventWebContent($eventWebContent->getType()));
        if (!$this->eventWebContents->contains($eventWebContent)) {
            $this->eventWebContents->add($eventWebContent);
        }
    }

    final public function removeEventWebContent(?EventWebContent $eventWebContent): void
    {
        if (null !== $eventWebContent) {
            $this->eventWebContents->removeElement($eventWebContent);
        }
    }

    final public function getEventWebContent(?string $type = 'html'): ?EventWebContent
    {
        return $this->getEventWebContents($type)->first();
    }

    final public function getEventWebContents(?string $type = null): Collection
    {
        if (null !== $type) {
            $this->getEventWebContents()->filter(fn(EventWebContent $webContent) => $type === $webContent->getType());
        }

        return $this->eventWebContents ?? new ArrayCollection();
    }

    final public function getLocation(?bool $recursive = false): ?Place
    {
        return $this->location ?? ($recursive && $this->getSuperEvent() ? $this->getSuperEvent()->getLocation() : null) ?? null;
    }

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

    final public function getSubEvents(): Collection
    {
        return $this->subEvents ?? new ArrayCollection();
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

    final public function getAllowedFlagsAggregatedByType(?EventParticipantType $eventParticipantType = null): array
    {
        $flags = [];
        foreach ($this->getEventParticipantFlagInEventConnections($eventParticipantType) as $flagInEventConnection) {
            assert($flagInEventConnection instanceof EventParticipantFlagInEventConnection);
            $flag = $flagInEventConnection->getEventParticipantFlag();
            if ($flag) {
                $flagTypeId = $flag->getEventParticipantFlagType() ? $flag->getEventParticipantFlagType()->getSlug() : '';
                $flags[$flagTypeId][] = $flag;
            }
        }

        return $flags;
    }

    final public function getEventParticipantFlagInEventConnections(
        EventParticipantType $participantType = null,
        ?EventParticipantFlag $participantFlag = null
    ): Collection {
        $out = $this->eventParticipantFlagInEventConnections ?? new ArrayCollection();
        if (null !== $participantType) {
            $out = $out->filter(
                fn(EventParticipantFlagInEventConnection $c) => $c->getEventParticipantType() && $participantType->getId() === $c->getEventParticipantType()->getId()
            );
        }
        if (null !== $participantFlag) {
            $out = $out->filter(
                fn(EventParticipantFlagInEventConnection $c) => $c->getEventParticipantFlag() && $participantFlag->getId() === $c->getEventParticipantFlag()->getId()
            );
        }

        return $out;
    }

    /**
     * True if registrations for specified participant type (or any if not specified) is allowed in some datetime (or now if not specified).
     *
     * @param EventParticipantType $participantType
     * @param DateTime|null        $dateTime
     *
     * @return bool
     */
    final public function isRegistrationsAllowed(?EventParticipantType $participantType = null, ?DateTime $dateTime = null): bool
    {
        return $this->getEventRegistrationRanges($participantType, $dateTime)->count() > 0;
    }

    final public function getEventRegistrationRanges(?EventParticipantType $participantType = null, ?DateTime $dateTime = null): Collection
    {
        if (null !== $participantType || null !== $dateTime) {
            return $this->getEventRegistrationRanges()->filter(fn(EventRegistrationRange $range) => $range->isApplicable($participantType, $dateTime));
        }

        return $this->eventRegistrationRanges ?? new ArrayCollection();
    }

    final public function __toString(): string
    {
        $range = $this->getRangeAsText();

        return $this->getName().($range ? (' ('.$range.')') : null);
    }

    final public function getAllowedEventParticipantFlagAmount(?EventParticipantFlag $participantFlag, ?EventParticipantType $participantType): int
    {
        $allowedAmount = 0;
        foreach ($this->getEventParticipantFlagInEventConnections($participantType, $participantFlag) as $flagInEventConnection) {
            assert($flagInEventConnection instanceof EventParticipantFlagInEventConnection);
            $allowedAmount += $flagInEventConnection->getActive() ? $flagInEventConnection->getMaxAmountInEvent() : 0;
        }

        return $allowedAmount;
    }

    final public function getEventFlagConnections(): ?Collection
    {
        return $this->eventFlagConnections ?? new ArrayCollection();
    }

    final public function removeEventFlagConnection(?EventFlagNewConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && $this->eventFlagConnections->removeElement($eventContactFlagConnection)) {
            $eventContactFlagConnection->setEvent(null);
        }
    }

    public function getGeneratedSlug(): string /// TODO: Used somewhere?
    {
        if ($this->isBatchOrYear() && $this->getStartYear()) {
            return $this->getStartYear().($this->isBatch() ? '-'.$this->getSeqId() : null);
        }

        return (string)$this->getId();
    }

    public function isBatchOrYear(): bool
    {
        return $this->isYear() || $this->isBatch();
    }

    public function isYear(): bool
    {
        return $this->getEventType() && EventType::YEAR_OF_EVENT === $this->getEventType();
    }

    final public function getEventType(): ?EventType
    {
        return $this->eventType;
    }

    final public function setEventType(?EventType $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function isBatch(): bool
    {
        return $this->getEventType() && EventType::BATCH_OF_EVENT === $this->getEventType();
    }

    public function getStartYear(): ?int
    {
        return (int)$this->getStartByFormat(DateTimeUtils::DATE_TIME_YEARS);
    }

    public function getSeqId(): ?int
    {
        return $this->getEventSeries() ? $this->getEventSeries()->getSeqId($this) : null;
    }

    final public function getEventSeries(): ?EventSeries
    {
        return $this->eventSeries;
    }

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

    public function isSuperEvents(?Event $event, ?bool $recursive = true): bool
    {
        return in_array($event, $recursive ? $this->getSuperEvents() : [$this->getSuperEvent()], true);
    }

    public function getSuperEvents(): array
    {
        return null === $this->getSuperEvent() ? [...$this->getSuperEvents(), $this->getSuperEvent()] : [$this];
    }

    public function isSuperEventRequired(?EventParticipantType $participantType): bool
    {
        return $this->getEventPrices($participantType)->exists(fn(EventPrice $price) => $price->isSuperEventRequired());
    }
}
