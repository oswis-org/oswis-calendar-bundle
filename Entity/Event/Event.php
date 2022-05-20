<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\Place;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\BankAccount;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
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
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_event")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_CUSTOMER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_events_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_events_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_CUSTOMER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_event_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_event_put"}, "enable_max_depth"=true}
 *     },
 *     "delete"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_delete"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter::class)
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
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
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\Participant", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Participant $organizer = null;

    /**
     * @var Collection<EventFlagConnection> $flagConnections
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventFlagConnection",
     *     cascade={"all"},
     *     fetch="EAGER",
     *     mappedBy="event",
     * )
     */
    protected Collection $flagConnections;

    /**
     * Parent event (if this is not top level event).
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", inversedBy="subEvents", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $superEvent = null;

    /**
     * @var Collection<Event> $subEvents
     * @Doctrine\ORM\Mapping\OneToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", mappedBy="superEvent")
     */
    protected Collection $subEvents;

    /**
     * @var Collection<EventContent> $contents
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventContent",
     *     cascade={"all"},
     *     mappedBy="event"
     * )
     */
    protected Collection $contents;

    /**
     * @var Collection<EventImage> $images
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventImage", mappedBy="event", cascade={"all"}, orphanRemoval=true
     * )
     */
    protected Collection $images;

    /**
     * @var Collection<EventFile> $files
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventFile", mappedBy="event", cascade={"all"}, orphanRemoval=true
     * )
     */
    protected Collection $files;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="type_id", referencedColumnName="id")
     */
    private ?EventCategory $category = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\EventGroup", inversedBy="events", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(name="event_series_id", referencedColumnName="id")
     * @Symfony\Component\Serializer\Annotation\MaxDepth(1)
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
        $this->images = new ArrayCollection();
        $this->files = new ArrayCollection();
        $this->setCategory($category);
        $this->setSuperEvent($superEvent);
        $this->setGroup($group);
        $this->setFieldsFromNameable($nameable);
        $this->setPlace($place);
        $this->setDateTimeRange($dateTimeRange);
        $this->setFieldsFromPublicity($publicity);
    }

    public function getOneImage(bool $recursive = false, string $type = EventImage::TYPE_IMAGE): ?EventImage
    {
        $image = $this->getImages($type)->first();
        if ($image instanceof EventImage) {
            return $image;
        }

        return true === $recursive ? $this->getSuperEvent()?->getOneImage(true, $type) : null;
    }

    public function getImages(?string $type = null): Collection
    {
        $images = $this->images;
        if (!empty($type)) {
            $images = $images->filter(fn(mixed $eventImage) => $eventImage instanceof EventImage && $eventImage->getType() === $type,);
        }

        /** @var Collection<EventImage> $images */
        return $images;
    }

    public function getType(): ?string
    {
        return $this->getCategory()?->getType();
    }

    public function getCategory(): ?EventCategory
    {
        return $this->category;
    }

    public function setCategory(?EventCategory $category): void
    {
        $this->category = $category;
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
        $this->superEvent?->addSubEvent($this);
    }

    public function getOneFile(bool $recursive = false, ?string $type = null): ?EventFile
    {
        $file = $this->getFiles($type)->first();
        if ($file instanceof EventFile) {
            return $file;
        }

        return true === $recursive ? $this->getSuperEvent()?->getOneFile(true, $type) : null;
    }

    public function getFiles(?string $type = null): Collection
    {
        $files = $this->files;
        if (!empty($type)) {
            $files = $files->filter(fn(mixed $eventFile) => $eventFile instanceof EventFile && $eventFile->getType() === $type,);
        }

        /** @var Collection<EventFile> $files */
        return $files;
    }

    public function addImage(?EventImage $image): void
    {
        if (null !== $image && !$this->getImages()->contains($image)) {
            $this->getImages()->add($image);
            $image->setEvent($this);
        }
    }

    public function removeImage(?EventImage $image): void
    {
        if (null !== $image && $this->getImages()->removeElement($image)) {
            $image->setEvent(null);
        }
    }

    public function addFile(?EventFile $file): void
    {
        if (null !== $file && !$this->getFiles()->contains($file)) {
            $this->getFiles()->add($file);
            $file->setEvent($this);
        }
    }

    public function removeFile(?EventFile $file): void
    {
        if (null !== $file && $this->getFiles()->removeElement($file)) {
            $file->setEvent(null);
        }
    }

    public function isRoot(): bool
    {
        return !$this->getSuperEvent();
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

    public function addContent(?EventContent $eventWebContent): void
    {
        if (null !== $eventWebContent && !$this->getContents()->contains($eventWebContent)) {
            $this->removeContent($this->getContent($eventWebContent->getType()));
            $this->getContents()->add($eventWebContent);
        }
    }

    public function getContents(?string $type = null, ?bool $recursive = false): Collection
    {
        if (null !== $type) {
            $contents = $this->getContents()->filter(fn(mixed $webContent) => $webContent instanceof EventContent && $type === $webContent->getType(),);

            return ($recursive && $contents->count() < 1 ? $this->getSuperEvent()?->getContents($type) : $contents) ?? new ArrayCollection();
        }

        return $this->contents;
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
        return $this->place ?? ($recursive ? $this->getSuperEvent()?->getPlace() : null) ?? null;
    }

    public function setPlace(?Place $event): void
    {
        $this->place = $event;
    }

    public function getOrganizerContact(): ?AbstractContact
    {
        return $this->getOrganizer()?->getContact();
    }

    public function getOrganizer(?bool $recursive = false): ?Participant
    {
        return $this->organizer ?? ($recursive ? $this->getSuperEvent()?->getOrganizer() : null) ?? null;
    }

    public function setOrganizer(?Participant $organizer): void
    {
        $this->organizer = $organizer;
    }

    public function getBankAccount(bool $recursive = false): ?BankAccount
    {
        $bankAccount = $this->traitGetBankAccount();
        if (empty($bankAccount->getFull())) {
            $bankAccount = (true === $recursive && null !== $this->getSuperEvent()) ? $this->getSuperEvent()->getBankAccount(true) : null;
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

    public function getSubEvents(): Collection
    {
        return $this->subEvents;
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
            $connections = $connections->filter(fn(mixed $conn) => $conn instanceof EventFlagConnection && $conn->isActive(),);
        }

        return $connections;
    }

    public function removeFlagConnection(EventFlagConnection $flagConnection): void
    {
        $this->getFlagConnections()->removeElement($flagConnection);
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

        return ''.$name;
    }

    public function isBatchOrYear(): bool
    {
        return $this->isYear() || $this->isBatch();
    }

    public function isYear(): bool
    {
        return null !== $this->getCategory() && EventCategory::YEAR_OF_EVENT === $this->getCategory()->getType();
    }

    public function isBatch(): bool
    {
        return EventCategory::BATCH_OF_EVENT === $this->getCategory()?->getType();
    }

    public function getStartYear(): ?int
    {
        return (int)$this->getStartByFormat('Y');
    }

    public function getSeqId(): ?int
    {
        return $this->getGroup()?->getSeqId($this);
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
    }

    public function isEventSuperEvent(?Event $event = null, ?bool $recursive = true): bool
    {
        return (null !== $event)
               && in_array($event, $recursive ? $this->getSuperEvents() : [$this->getSuperEvent()], true);
    }

    public function getSuperEvents(): array
    {
        return null === $this->getSuperEvent() ? [] : [...$this->getSuperEvent()->getSuperEvents(), $this->getSuperEvent()];
    }
}
