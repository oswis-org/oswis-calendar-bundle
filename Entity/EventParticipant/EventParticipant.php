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
use Doctrine\ORM\Mapping as ORM;
use Exception;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Interfaces\BasicEntityInterface;
use OswisOrg\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\BasicMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\InfoMailSentTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\PriorityTrait;
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
class EventParticipant implements BasicEntityInterface
{
    use BasicEntityTrait;
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
    protected ?EventParticipantType $eventParticipantType = null;

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
    protected ?Collection $eventParticipantNotes = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment",
     *     cascade={"all"},
     *     mappedBy="eventParticipant",
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected ?Collection $eventParticipantPayments = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagNewConnection",
     *     cascade={"all"},
     *     mappedBy="eventParticipant",
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $eventParticipantFlagConnections = null;

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
     * @ORM\Column(type="string", nullable=true)
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
        $this->setEventParticipantType($participantType);
        $this->setEventParticipantNotes($participantNotes);
        $this->setEventParticipantPayments(new ArrayCollection());
        $this->setEventParticipantFlagConnections($participantFlagConnections);
        $this->setDeleted($deleted);
        $this->setPriority($priority);
    }

    public static function filterEventParticipants(Collection $participants, ?bool $includeNotActivated = true): Collection
    {
        $eventParticipants = new ArrayCollection();
        foreach ($participants as $newEventParticipant) {
            assert($newEventParticipant instanceof self);
            if (!$includeNotActivated && !$newEventParticipant->hasActivatedContactUser()) {
                continue;
            }
            if (!$eventParticipants->contains($newEventParticipant)) {
                $eventParticipants->add($newEventParticipant);
            }
        }

        return $eventParticipants;
    }

    public function hasActivatedContactUser(?DateTime $referenceDateTime = null): bool
    {
        try {
            return $this->getContact() && $this->getContact()
                    ->getContactPersons($referenceDateTime, true)
                    ->count() > 0;
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

    public static function sort(Collection $eventParticipants): Collection
    {
        $participants = $eventParticipants->toArray();
        self::sortArray($participants);

        return new ArrayCollection($participants);
    }

    public static function sortArray(array &$eventParticipants): void
    {
        usort(
            $eventParticipants,
            static function (EventParticipant $arg1, EventParticipant $arg2) {
                if (!$arg1->getContact() || !$arg2->getContact()) {
                    $cmpResult = 0;
                } else {
                    $cmpResult = strcmp(
                        $arg1->getContact()
                            ->getSortableContactName(),
                        $arg2->getContact()
                            ->getSortableContactName()
                    );
                }

                return $cmpResult === 0 ? AbstractRevision::cmpId($arg2->getId(), $arg1->getId()) : $cmpResult;
            }
        );
    }

    public function isFormal(bool $recursive = false): bool
    {
        if ($recursive && null === $this->formal) {
            return $this->getEventParticipantType() ? $this->getEventParticipantType()
                ->isFormal() : false;
        }

        return $this->formal;
    }

    public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        $this->eventParticipantType = $eventParticipantType;
    }

    public function setFormal(?bool $formal): void
    {
        $this->formal = $formal;
    }

    public function isManager(): bool
    {
        return false;
        // return in_array($this->getType(), self::MANAGEMENT_TYPES, true);
    }

    public function getName(): ?string
    {
        return null !== $this->getContact() ? $this->getContact()
            ->getContactName() : null;
    }

    /**
     * @param EventParticipantFlagNewConnection|null $newConnection
     *
     * @throws EventCapacityExceededException
     */
    public function addEventParticipantFlagConnection(?EventParticipantFlagNewConnection $newConnection): void
    {
        if (null !== $newConnection && !$this->eventParticipantFlagConnections->contains($newConnection)) {
            $this->eventParticipantFlagConnections->add($newConnection);
            $newConnection->setEventParticipant($this);
        }
    }

    /**
     * @param EventParticipantFlagNewConnection|null $eventContactFlagConnection
     *
     * @throws EventCapacityExceededException
     */
    public function removeEventParticipantFlagConnection(?EventParticipantFlagNewConnection $eventContactFlagConnection): void
    {
        if ($eventContactFlagConnection && $this->eventParticipantFlagConnections->removeElement($eventContactFlagConnection)) {
            $eventContactFlagConnection->setEventParticipant(null);
        }
    }

    public function removeEmptyEventParticipantNotes(): void
    {
        $this->setEventParticipantNotes(
            $this->getEventParticipantNotes()
                ->filter(fn(EventParticipantNote $note): bool => !empty($note->getTextValue()))
        );
    }

    public function getEventParticipantNotes(): Collection
    {
        return $this->eventParticipantNotes ?? new ArrayCollection();
    }

    public function setEventParticipantNotes(?Collection $newEventParticipantNotes): void
    {
        $this->eventParticipantNotes = $newEventParticipantNotes ?? new ArrayCollection();
    }

    public function removeEventParticipantNote(?EventParticipantNote $eventParticipantNote): void
    {
        if ($eventParticipantNote) {
            $this->eventParticipantNotes->removeElement($eventParticipantNote);
        }
    }

    public function addEventParticipantNote(?EventParticipantNote $eventParticipantNote): void
    {
        if ($eventParticipantNote && !$this->eventParticipantNotes->contains($eventParticipantNote)) {
            $this->eventParticipantNotes->add($eventParticipantNote);
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
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getPriceRest(): int
    {
        return $this->getPrice() - $this->getPriceDeposit();
    }

    /**
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getPrice(): int
    {
        if (null === $this->getEvent()) {
            throw new PriceInvalidArgumentException(' (událost nezadána)');
        }
        if (null === $this->getEventParticipantType()) {
            throw new PriceInvalidArgumentException(' (typ uživatele nezadán)');
        }
        $dateTime = $this->getCreatedDateTime() ?? new DateTime();
        $price = $this->getEvent()
                ->getPrice($this->getEventParticipantType(), $dateTime) + $this->getFlagsPrice();

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

    public function getFlagsPrice(?EventParticipantFlagType $eventParticipantFlagType = null): int
    {
        $price = 0;
        foreach ($this->getEventParticipantFlags($eventParticipantFlagType) as $flag) {
            assert($flag instanceof EventParticipantFlag);
            $price += $flag->getPrice();
        }

        return $price;
    }

    public function getEventParticipantFlags(?EventParticipantFlagType $eventParticipantFlagType = null): Collection
    {
        return $this->getEventParticipantFlagConnections($eventParticipantFlagType)
            ->map(
                fn(EventParticipantFlagNewConnection $connection) => $connection->getEventParticipantFlag()
            );
    }

    public function getEventParticipantFlagConnections(?EventParticipantFlagType $eventParticipantFlagType = null): Collection
    {
        if (null === $eventParticipantFlagType) {
            return $this->eventParticipantFlagConnections ?? new ArrayCollection();
        }

        return $this->eventParticipantFlagConnections->filter(
            static function (EventParticipantFlagNewConnection $eventParticipantFlagConnection) use ($eventParticipantFlagType) {
                try {
                    $flag = $eventParticipantFlagConnection->getEventParticipantFlag();
                    $type = $flag ? $flag->getEventParticipantFlagType() : null;

                    return $type && $type->getId() === $eventParticipantFlagType->getId();
                } catch (Exception $e) {
                    return false;
                }
            }
        );
    }

    /**
     * @param Collection|null $newConnections
     *
     * @throws EventCapacityExceededException
     */
    public function setEventParticipantFlagConnections(?Collection $newConnections): void
    {
        $this->eventParticipantFlagConnections ??= new ArrayCollection();
        $newConnections ??= new ArrayCollection();
        foreach ($this->eventParticipantFlagConnections as $oldConnection) {
            if (!$newConnections->contains($oldConnection)) {
                $this->removeEventParticipantFlagConnection($oldConnection);
            }
        }
        foreach ($newConnections as $newConnection) {
            if (!$this->eventParticipantFlagConnections->contains($newConnection)) {
                $this->addEventParticipantFlagConnection($newConnection);
            }
        }
    }

    /**
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getPriceDeposit(): ?int
    {
        if (!$this->getEvent() || !$this->getEventParticipantType()) {
            throw new PriceInvalidArgumentException();
        }
        $price = $this->getEvent()
            ->getDeposit($this->getEventParticipantType());

        return $price < 0 ? 0 : $price;
    }

    public function getPaidPrice(): int
    {
        $paid = 0;
        foreach ($this->getEventParticipantPayments() as $eventParticipantPayment) {
            assert($eventParticipantPayment instanceof EventParticipantPayment);
            $paid += $eventParticipantPayment->getNumericValue();
        }

        return $paid;
    }

    public function getEventParticipantPayments(): ?Collection
    {
        return $this->eventParticipantPayments ?? new ArrayCollection();
    }

    public function setEventParticipantPayments(?Collection $newEventParticipantPayments): void
    {
        $this->eventParticipantPayments = $this->eventParticipantPayments ?? new ArrayCollection();
        $newEventParticipantPayments = $newEventParticipantPayments ?? new ArrayCollection();
        foreach ($this->eventParticipantPayments as $oldPayment) {
            if (!$newEventParticipantPayments->contains($oldPayment)) {
                $this->removeEventParticipantPayment($oldPayment);
            }
        }
        foreach ($newEventParticipantPayments as $newPayment) {
            if (!$this->eventParticipantPayments->contains($newPayment)) {
                $this->addEventParticipantPayment($newPayment);
            }
        }
    }

    public function hasFlag(EventParticipantFlag $flag): bool
    {
        return $this->getEventParticipantFlags()
            ->exists(fn(EventParticipantFlag $f) => $flag->getId() === $f->getId());
    }

    public function hasFlagOfTypeOfType(?string $flagType): bool
    {
        return $this->getEventParticipantFlags()
            ->exists(fn(EventParticipantFlag $f) => $flagType && $flagType === $f->getTypeOfType());
    }

    /**
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingPrice(): int
    {
        return $this->getPrice() - $this->getPaidPrice();
    }

    /**
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingDeposit(): int
    {
        $remaining = null !== $this->getPriceDeposit() ? $this->getPriceDeposit() - $this->getPaidPrice() : 0;

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * @return float
     * @throws PriceInvalidArgumentException
     */
    public function getPaidPricePercent(): float
    {
        return $this->getPaidPrice() / $this->getPrice();
    }

    public function removeEventParticipantPayment(?EventParticipantPayment $eventParticipantPayment): void
    {
        if ($eventParticipantPayment && $this->eventParticipantPayments->removeElement($eventParticipantPayment)) {
            $eventParticipantPayment->setEventParticipant(null);
        }
    }

    public function addEventParticipantPayment(?EventParticipantPayment $eventParticipantPayment): void
    {
        if ($eventParticipantPayment && !$this->eventParticipantPayments->contains($eventParticipantPayment)) {
            $this->eventParticipantPayments->add($eventParticipantPayment);
            $eventParticipantPayment->setEventParticipant($this);
        }
    }

    /**
     * Get variable symbol of this eventParticipant (default is cropped phone number).
     */
    public function getVariableSymbol(): ?string
    {
        $phone = preg_replace(
            '/\s/',
            '',
            $this->getContact() ? $this->getContact()
                ->getPhone() : null
        );

        return substr(trim($phone), strlen(trim($phone)) - 9, 9);
    }

    public function getFlagsAggregatedByType(): array
    {
        $flags = [];
        foreach ($this->getEventParticipantFlags() as $flag) {
            if ($flag instanceof EventParticipantFlag) {
                $flagTypeId = $flag->getEventParticipantFlagType() ? $flag->getEventParticipantFlagType()
                    ->getSlug() : '';
                $flags[$flagTypeId] ??= [];
                $flags[$flagTypeId][] = $flag;
            }
        }

        return $flags;
    }

    public function destroyRevisions(): void
    {
    }
}
