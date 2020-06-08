<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisAddressBookBundle\Entity\Place;
use OswisOrg\OswisCalendarBundle\Entity\MediaObjects\EventImage;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantTypeInEventConnection;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\BankAccount;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Payment\BankAccountTrait;
use OswisOrg\OswisCoreBundle\Utils\DateTimeUtils;
use function assert;

/**
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\EventRepository")
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
class Event implements NameableInterface
{
    use NameableTrait;
    use DateRangeTrait;
    use ColorTrait;
    use BankAccountTrait {
        getBankAccount as traitGetBankAccount;
    }
    use DeletedTrait;
    use EntityPublicTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisAddressBookBundle\Entity\Place", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Place $place = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventFlagConnection", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_event_flag_connection_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_flag_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $flagConnections = null;

    /**
     * Parent event (if this is not top level event).
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", inversedBy="subEvents", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $superEvent = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", mappedBy="superEvent")
     */
    protected ?Collection $subEvents = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventContent", cascade={"all"})
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_event_content",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_content_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $contents = null;

    /**
     * @Doctrine\ORM\Mapping\OneToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\MediaObjects\EventImage", cascade={"all"}, fetch="EAGER")
     */
    protected ?EventImage $image = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="type_id", referencedColumnName="id")
     */
    private ?EventCategory $category = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="EventGroup", inversedBy="events", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="event_series_id", referencedColumnName="id")
     */
    private ?EventGroup $group = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?Event $superEvent = null,
        ?Place $place = null,
        ?EventCategory $category = null,
        ?DateTimeRange $dateTimeRange = null,
        ?EventGroup $group = null,
        ?Publicity $publicity = null
    ) {
        $this->subEvents = new ArrayCollection();
        $this->contents = new ArrayCollection();
        $this->flagConnections = new ArrayCollection();
        $this->setCategory($category);
        $this->setSuperEvent($superEvent);
        $this->setGroup($group);
        $this->setFieldsFromNameable($nameable);
        $this->setPlace($place);
        $this->setDateTimeRange($dateTimeRange);
        $this->setFieldsFromPublicity($publicity);
    }

    public function getImage(bool $recursive = false): ?EventImage
    {
        if (true === $recursive && empty($this->image) && $this->getSuperEvent()) {
            return $this->getSuperEvent()->getImage(true);
        }

        return $this->image;
    }

    public function setImage(?EventImage $image): void
    {
        $this->image = $image;
    }

    public function isRoot(): bool
    {
        return $this->getSuperEvent() ? false : true;
    }

    public function getSuperEvent(): ?Event
    {
        return $this->superEvent;
    }

    public function setSuperEvent(?Event $event): void
    {
        if ($this->superEvent && $event !== $this->superEvent) {
            $this->superEvent->removeSubEvent($this);
        }
        $this->superEvent = $event;
        if ($this->superEvent) {
            $this->superEvent->addSubEvent($this);
        }
    }

    public function addSubEvent(?Event $event): void
    {
        if (null !== $event && !$this->getSubEvents()->contains($event)) {
            $this->getSubEvents()->add($event);
            $event->setSuperEvent($this);
        }
    }

    public function removeSubEvent(?Event $event): void
    {
        if (null !== $event && $this->getSubEvents()->removeElement($event)) {
            $event->setSuperEvent(null);
        }
    }

    public function getSubEvents(): Collection
    {
        return $this->subEvents ?? new ArrayCollection();
    }

    public function addWebContent(?EventContent $eventWebContent): void
    {
        if (null !== $eventWebContent && !$this->getContents()->contains($eventWebContent)) {
            $this->removeContent($this->getContent($eventWebContent->getType()));
            $this->getContents()->add($eventWebContent);
        }
    }

    public function getContents(?string $type = null, ?bool $recursive = false): Collection
    {
        if (null !== $type) {
            $contents = $this->getContents()->filter(fn(EventContent $webContent) => $type === $webContent->getType());

            return $recursive && $contents->count() < 1 && $this->getSuperEvent() ? $this->getSuperEvent()->getContents($type) : $contents;
        }

        return $this->contents ?? new ArrayCollection();
    }

    public function removeContent(?EventContent $eventContent): void
    {
        $this->getContents()->removeElement($eventContent);
    }

    public function getContent(?string $type = 'html'): ?EventContent
    {
        $content = $this->getContents($type, true)->first();

        return $content instanceof EventContent ? $content : null;
    }

    public function getPlace(?bool $recursive = false): ?Place
    {
        return $this->place ?? ($recursive && $this->getSuperEvent() ? $this->getSuperEvent()->getPlace() : null) ?? null;
    }

    public function setPlace(?Place $event): void
    {
        $this->place = $event;
    }

    public function getBankAccount(bool $recursive = false): ?BankAccount
    {
        $bankAccount = $this->traitGetBankAccount();
        if (empty($bankAccount->getFull())) {
            $bankAccount = (true === $recursive && null !== $this->getSuperEvent()) ? $this->getSuperEvent()->getBankAccount($recursive) : null;
        }

        return $bankAccount;
    }

    public function getStartDateTimeRecursive(): ?DateTime
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

    public function addFlagConnection(EventFlagConnection $flagConnection): void
    {
        if (!$this->getFlagConnections()->contains($flagConnection)) {
            $this->getFlagConnections()->add($flagConnection);
        }
    }

    public function getFlagConnections(bool $onlyActive = false): Collection
    {
        $connections = $this->flagConnections ?? new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(EventFlagConnection $conn) => $conn->isActive());
        }

        return $connections;
    }

    public function removeFlagConnection(EventFlagConnection $flagConnection): void
    {
        $this->getFlagConnections()->remove($flagConnection);
    }

    public function getEndDateTimeRecursive(): ?DateTime
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

    public function __toString(): string
    {
        return $this->getExtendedName();
    }

    public function getExtendedName(bool $range = true): string
    {
        $name = $this->getName();
        if ($range && !empty($rangeString = $this->getRangeAsText())) {
            $name .= " ($rangeString)";
        }

        return $name;
    }

    public function isBatchOrYear(): bool
    {
        return $this->isYear() || $this->isBatch();
    }

    public function isYear(): bool
    {
        return null !== $this->getCategory() && EventCategory::YEAR_OF_EVENT === $this->getCategory()->getType();
    }

    public function getCategory(): ?EventCategory
    {
        return $this->category;
    }

    public function setCategory(?EventCategory $category): void
    {
        $this->category = $category;
    }

    public function isBatch(): bool
    {
        return $this->getCategory() && EventCategory::BATCH_OF_EVENT === $this->getCategory()->getType();
    }

    public function getStartYear(): ?int
    {
        return (int)$this->getStartByFormat(DateTimeUtils::DATE_TIME_YEARS);
    }

    public function getSeqId(): ?int
    {
        return $this->getGroup() ? $this->getGroup()->getSeqId($this) : null;
    }

    public function getGroup(): ?EventGroup
    {
        return $this->group;
    }

    public function setGroup(?EventGroup $group): void
    {
        if (null !== $this->group && $group !== $this->group) {
            $this->group->removeEvent($this);
        }
        $this->group = $group;
        if (null !== $group && $this->group !== $group) {
            $group->addEvent($this);
        }
    }

    public function getType(): ?string
    {
        return $this->getCategory() ? $this->getCategory()->getType() : null;
    }

    public function isEventSuperEvent(?Event $event, ?bool $recursive = true): bool
    {
        return in_array($event, $recursive ? $this->getSuperEvents() : [$this->getSuperEvent()], true);
    }

    public function getSuperEvents(): array
    {
        return null === $this->getSuperEvent() ? [...$this->getSuperEvents(), $this->getSuperEvent()] : [$this];
    }
}
