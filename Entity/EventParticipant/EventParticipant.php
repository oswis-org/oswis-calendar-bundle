<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCoreBundle\Entity\Revisions\AbstractRevision;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\InfoMailSentTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use function assert;

/**
 * Participation of contact in event (attendee, sponsor, organizer, guest, partner...).
 *
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\EventParticipantRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participants_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participants_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_put"}, "enable_max_depth"=true}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_delete"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class, properties={
 *     "id": "ASC",
 *     "createdDateTime",
 *     "id",
 *     "name",
 *     "shortName",
 *     "slug",
 *     "color",
 *     "startDateTime",
 *     "endDateTime",
 *     "event.type.id",
 *     "event.type.name",
 *     "event.type.shortName",
 *     "event.type.slug",
 *     "event.type.color",
 *     "contact.id",
 *     "contact.contactName",
 *     "contact.sortableName",
 *     "contact.contactDetails.content",
 *     "eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 * @ApiFilter(SearchFilter::class, properties={
 *     "id": "iexact",
 *     "id": "iexact",
 *     "name": "ipartial",
 *     "shortName": "ipartial",
 *     "slug": "ipartial",
 *     "color": "ipartial",
 *     "startDateTime": "ipartial",
 *     "endDateTime": "ipartial",
 *     "event.type.id": "iexact",
 *     "event.type.name": "ipartial",
 *     "event.type.shortName": "ipartial",
 *     "event.type.slug": "ipartial",
 *     "event.type.color": "ipartial",
 *     "contact.id": "iexact",
 *     "contact.contactName": "ipartial",
 *     "contact.contactDetails.content": "ipartial",
 *     "eventParticipantFlagConnections.eventParticipantFlag.name": "ipartial",
 *     "eventParticipantFlagConnections.eventParticipantFlag.shortName": "ipartial",
 *     "eventParticipantFlagConnections.eventParticipantFlag.slug": "ipartial"
 * })
 * @ApiFilter(ExistsFilter::class, properties={"deleted"})
 * @Searchable({
 *     "id",
 *     "id",
 *     "name",
 *     "shortName",
 *     "slug",
 *     "color",
 *     "startDateTime",
 *     "endDateTime",
 *     "event.type.id",
 *     "event.type.name",
 *     "event.type.shortName",
 *     "event.type.slug",
 *     "event.type.color",
 *     "contact.id",
 *     "contact.contactName",
 *     "contact.contactDetails.content",
 *     "eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipant implements BasicInterface
{
    use BasicTrait;
    use DeletedTrait;
    use BasicMailConfirmationTrait;
    use InfoMailSentTrait;
    use PriorityTrait;

    /**
     * Type of relation between contact and event - attendee, staff....
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     * @MaxDepth(1)
     */
    protected ?EventParticipantType $participantType = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantNote",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_event_participant_note_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="event_participant_note_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $participantNotes = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment",
     *     cascade={"all"},
     *     mappedBy="eventParticipant",
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected ?Collection $participantPayments = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="EventParticipantFlagConnection",
     *     cascade={"all"},
     *     mappedBy="eventParticipant",
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $participantFlagConnections = null;

    /**
     * Related contact (person or organization).
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?AbstractContact $contact = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?bool $formal = null;

    /**
     * @param AbstractContact|null      $contact
     * @param Event|null                $event
     * @param EventParticipantType|null $participantType
     * @param Collection|null           $participantFlagConnections
     * @param Collection|null           $participantNotes
     * @param DateTime|null             $deleted
     * @param int|null                  $priority
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?AbstractContact $contact = null,
        ?Event $event = null,
        ?EventParticipantType $participantType = null,
        ?Collection $participantFlagConnections = null,
        ?Collection $participantNotes = null,
        ?DateTime $deleted = null,
        ?int $priority = null
    ) {
        $this->setContact($contact);
        $this->setEvent($event);
        $this->setParticipantType($participantType);
        $this->setParticipantNotes($participantNotes);
        $this->setParticipantPayments(new ArrayCollection());
        $this->setParticipantFlagConnections($participantFlagConnections);
        $this->setDeleted($deleted);
        $this->setPriority($priority);
    }

    public static function filterCollection(Collection $participants, ?bool $includeNotActivated = true): Collection
    {
        $resultCollection = new ArrayCollection();
        foreach ($participants as $newEventParticipant) {
            assert($newEventParticipant instanceof self);
            if (!$includeNotActivated && !$newEventParticipant->hasActivatedContactUser()) {
                continue;
            }
            if (!$resultCollection->contains($newEventParticipant)) {
                $resultCollection->add($newEventParticipant);
            }
        }

        return $resultCollection;
    }

    /**
     * Checks if there is some activated user assigned to this participant.
     *
     * @param DateTime|null $referenceDateTime
     *
     * @return bool
     */
    public function hasActivatedContactUser(?DateTime $referenceDateTime = null): bool
    {
        try {
            return $this->getContact() && $this->getContact()->getContactPersons($referenceDateTime, true)->count() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getContact(): ?AbstractContact
    {
        return $this->contact;
    }

    public function setContact(?AbstractContact $contact): void
    {
        $this->contact = $contact;
    }

    /**
     * Sort collection of event participants by name (and id).
     *
     * @param Collection $eventParticipants
     *
     * @return Collection
     */
    public static function sortCollection(Collection $eventParticipants): Collection
    {
        $participants = $eventParticipants->toArray();
        self::sortArray($participants);

        return new ArrayCollection($participants);
    }

    /**
     * Sort array of event participants by name (and id).
     *
     * @param array $eventParticipants
     */
    public static function sortArray(array &$eventParticipants): void
    {
        usort(
            $eventParticipants,
            static function (EventParticipant $arg1, EventParticipant $arg2) {
                if (!$arg1->getContact() || !$arg2->getContact()) {
                    $cmpResult = 0;
                } else {
                    $cmpResult = strcmp(
                        $arg1->getContact()->getSortableName(),
                        $arg2->getContact()->getSortableName()
                    );
                }

                return $cmpResult === 0 ? AbstractRevision::cmpId($arg2->getId(), $arg1->getId()) : $cmpResult;
            }
        );
    }

    /**
     * Recognizes if participant must be addressed in a formal way.
     *
     * @param bool $recursive
     *
     * @return bool Participant must be addressed in a formal way.
     */
    public function isFormal(bool $recursive = false): bool
    {
        if ($recursive && null === $this->formal) {
            return $this->getParticipantType() ? $this->getParticipantType()->isFormal() : false;
        }

        return (bool)$this->formal;
    }

    public function getParticipantType(): ?EventParticipantType
    {
        return $this->participantType;
    }

    public function setParticipantType(?EventParticipantType $participantType): void
    {
        $this->participantType = $participantType;
    }

    public function setFormal(?bool $formal): void
    {
        $this->formal = $formal;
    }

    /**
     * Checks if participant is marked as manager (by one of management participant types).
     * @return bool
     */
    public function isManager(): bool
    {
        $type = $this->getParticipantType();

        return null !== $type ? in_array($type->getType(), EventParticipantType::MANAGEMENT_TYPES, true) : false;
    }

    /**
     * Get participants name from assigned contact.
     * @return string|null
     */
    public function getName(): ?string
    {
        return null !== $this->getContact() ? $this->getContact()->getName() : null;
    }

    /**
     * @param EventParticipantFlagConnection|null $newConnection
     *
     * @throws EventCapacityExceededException
     */
    public function addParticipantFlagConnection(?EventParticipantFlagConnection $newConnection): void
    {
        if (null !== $newConnection && !$this->participantFlagConnections->contains($newConnection)) {
            $this->participantFlagConnections->add($newConnection);
            $newConnection->setEventParticipant($this);
        }
    }

    /**
     * @param EventParticipantFlagConnection|null $eventContactFlagConnection
     *
     * @throws EventCapacityExceededException
     */
    public function removeParticipantFlagConnection(?EventParticipantFlagConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && $this->participantFlagConnections->removeElement($eventContactFlagConnection)) {
            $eventContactFlagConnection->setEventParticipant(null);
        }
    }

    public function removeEmptyEventParticipantNotes(): void
    {
        $this->setParticipantNotes(
            $this->getParticipantNotes()->filter(fn(EventParticipantNote $note): bool => !empty($note->getTextValue()))
        );
    }

    public function getParticipantNotes(): Collection
    {
        return $this->participantNotes ?? new ArrayCollection();
    }

    public function setParticipantNotes(?Collection $newEventParticipantNotes): void
    {
        $this->participantNotes = $newEventParticipantNotes ?? new ArrayCollection();
    }

    public function removeParticipantNote(?EventParticipantNote $eventParticipantNote): void
    {
        if ($eventParticipantNote) {
            $this->participantNotes->removeElement($eventParticipantNote);
        }
    }

    public function addParticipantNote(?EventParticipantNote $eventParticipantNote): void
    {
        if ($eventParticipantNote && !$this->participantNotes->contains($eventParticipantNote)) {
            $this->participantNotes->add($eventParticipantNote);
        }
    }

    /**
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingRest(): int
    {
        return $this->getPriceRest() - $this->getPaidPrice() + $this->getPriceDeposit();
    }

    /**
     * Gets part of price that is not marked as deposit.
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getPriceRest(): int
    {
        return $this->getPrice() - $this->getPriceDeposit();
    }

    /**
     * Get whole price of event for this participant (including flags price).
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getPrice(): int
    {
        if (null === $this->getEvent()) {
            throw new PriceInvalidArgumentException(' (událost nezadána)');
        }
        if (null === $this->getParticipantType()) {
            throw new PriceInvalidArgumentException(' (typ uživatele nezadán)');
        }
        $dateTime = $this->getCreatedDateTime() ?? new DateTime();
        $price = $this->getEvent()->getPrice($this->getParticipantType(), $dateTime) + $this->getFlagsPrice();

        return $price < 0 ? 0 : $price;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    public function getFlagsPrice(?EventParticipantFlagType $eventParticipantFlagType = null, bool $onlyActive = true): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags($eventParticipantFlagType, $onlyActive) as $flag) {
            $price += $flag instanceof EventParticipantFlag ? $flag->getPrice() : 0;
        }

        return $price;
    }

    public function getParticipantFlags(?EventParticipantFlagType $eventParticipantFlagType = null, bool $onlyActive = false): Collection
    {
        return $this->getParticipantFlagConnections($eventParticipantFlagType, $onlyActive)->map(
            fn(EventParticipantFlagConnection $connection) => $connection->getEventParticipantFlag()
        );
    }

    public function getParticipantFlagConnections(?EventParticipantFlagType $participantFlagType = null, ?bool $onlyActive = false): Collection
    {
        $connections = $this->participantFlagConnections ?? new ArrayCollection();
        if (null !== $participantFlagType) {
            $connections = $connections->filter(
                static function (EventParticipantFlagConnection $connection) use ($participantFlagType) {
                    $flag = $connection->getEventParticipantFlag();
                    $type = $flag ? $flag->getEventParticipantFlagType() : null;

                    return null !== $type && $type->getId() === $participantFlagType->getId();
                }
            );
        }
        if ($onlyActive) {
            $connections = $connections->filter(
                fn(EventParticipantFlagConnection $conn) => $conn instanceof EventParticipantFlagConnection && $conn->isActive()
            );
        }

        return $connections;
    }

    /**
     * @param Collection|null $newConnections
     *
     * @throws EventCapacityExceededException
     */
    public function setParticipantFlagConnections(?Collection $newConnections): void
    {
        $this->participantFlagConnections ??= new ArrayCollection();
        $newConnections ??= new ArrayCollection();
        foreach ($this->participantFlagConnections as $oldConnection) {
            if (!$newConnections->contains($oldConnection)) {
                $this->removeParticipantFlagConnection($oldConnection);
            }
        }
        foreach ($newConnections as $newConnection) {
            if (!$this->participantFlagConnections->contains($newConnection)) {
                $this->addParticipantFlagConnection($newConnection);
            }
        }
    }

    /**
     * Gets part of price that is marked as deposit.
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getPriceDeposit(): ?int
    {
        if (!$this->getEvent() || !$this->getParticipantType()) {
            throw new PriceInvalidArgumentException();
        }
        $price = $this->getEvent()->getDeposit($this->getParticipantType());

        return $price < 0 ? 0 : $price;
    }

    /**
     * Gets part of price that was already paid.
     * @return int
     */
    public function getPaidPrice(): int
    {
        $paid = 0;
        foreach ($this->getParticipantPayments() as $eventParticipantPayment) {
            $paid += $eventParticipantPayment instanceof EventParticipantPayment ? $eventParticipantPayment->getNumericValue() : 0;
        }

        return $paid;
    }

    public function getParticipantPayments(): ?Collection
    {
        return $this->participantPayments ?? new ArrayCollection();
    }

    public function setParticipantPayments(?Collection $newEventParticipantPayments): void
    {
        $this->participantPayments = $this->participantPayments ?? new ArrayCollection();
        $newEventParticipantPayments = $newEventParticipantPayments ?? new ArrayCollection();
        foreach ($this->participantPayments as $oldPayment) {
            if (!$newEventParticipantPayments->contains($oldPayment)) {
                $this->removeParticipantPayment($oldPayment);
            }
        }
        foreach ($newEventParticipantPayments as $newPayment) {
            if (!$this->participantPayments->contains($newPayment)) {
                $this->addParticipantPayment($newPayment);
            }
        }
    }

    /**
     * Checks if participant contains given flag.
     *
     * @param EventParticipantFlag $flag
     * @param bool                 $onlyActive
     *
     * @return bool
     */
    public function hasFlag(EventParticipantFlag $flag, bool $onlyActive = true): bool
    {
        return $this->getParticipantFlags(null, $onlyActive)->exists(
            fn(EventParticipantFlag $oneFlag) => $flag->getId() === $oneFlag->getId()
        );
    }

    /**
     * Checks if participant has some flag of given type (given by type string).
     *
     * @param string|null $flagType
     * @param bool        $onlyActive
     *
     * @return bool Participant contains some flag of given type.
     */
    public function hasFlagOfTypeOfType(?string $flagType, bool $onlyActive = true): bool
    {
        return $this->getParticipantFlags(null, $onlyActive)->exists(
            fn(EventParticipantFlag $f) => $flagType && $f->getTypeOfType() === $flagType
        );
    }

    /**
     * Gets price remains to be paid.
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingPrice(): int
    {
        return $this->getPrice() - $this->getPaidPrice();
    }

    /**
     * Gets price deposit that remains to be paid.
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingDeposit(): int
    {
        $remaining = null !== $this->getPriceDeposit() ? $this->getPriceDeposit() - $this->getPaidPrice() : 0;

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Gets percentage of price paid (as float).
     * @return float
     * @throws PriceInvalidArgumentException
     */
    public function getPaidPricePercentage(): float
    {
        return $this->getPaidPrice() / $this->getPrice();
    }

    public function removeParticipantPayment(?EventParticipantPayment $eventParticipantPayment): void
    {
        if ($eventParticipantPayment && $this->participantPayments->removeElement($eventParticipantPayment)) {
            $eventParticipantPayment->setEventParticipant(null);
        }
    }

    public function addParticipantPayment(?EventParticipantPayment $eventParticipantPayment): void
    {
        if ($eventParticipantPayment && !$this->participantPayments->contains($eventParticipantPayment)) {
            $this->participantPayments->add($eventParticipantPayment);
            $eventParticipantPayment->setEventParticipant($this);
        }
    }

    /**
     * Get variable symbol of this eventParticipant (default is cropped phone number or ID).
     */
    public function getVariableSymbol(): ?string
    {
        $phone = $this->getContact() ? $this->getContact()->getPhone() : null;
        $symbol = preg_replace('/\s/', '', $phone);
        $symbol = substr(trim($symbol), strlen(trim($symbol)) - 9, 9);

        return empty($symbol) ? ''.$this->getId() : $symbol;
    }

    /**
     * Gets array of flags aggregated by their types.
     * @return array
     */
    public function getFlagsAggregatedByType(): array
    {
        $flags = [];
        foreach ($this->getParticipantFlags() as $flag) {
            if ($flag instanceof EventParticipantFlag) {
                $flagTypeSlug = $flag->getEventParticipantFlagType() ? $flag->getEventParticipantFlagType()->getSlug() : '0';
                $flags[$flagTypeSlug] ??= [];
                $flags[$flagTypeSlug][] = $flag;
            }
        }

        return $flags;
    }

    public function destroyRevisions(): void
    {
    }
}
