<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisAddressBookBundle\Entity\Organization;
use Zakjakub\OswisAddressBookBundle\Entity\Person;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Zakjakub\OswisCoreBundle\Exceptions\RevisionMissingException;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicMailConfirmationTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\EntityDeletedContainerTrait;
use function assert;

/**
 * Participation of contact in event (attendee, sponsor, organizer, guest, partner...).
 *
 * @Doctrine\ORM\Mapping\Entity(
 *     repositoryClass="Zakjakub\OswisCalendarBundle\Repository\EventParticipantRepository"
 * )
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
 *     "activeRevision.event.id",
 *     "activeRevision.event.activeRevision.name",
 *     "activeRevision.event.activeRevision.shortName",
 *     "activeRevision.event.activeRevision.slug",
 *     "activeRevision.event.activeRevision.color",
 *     "activeRevision.event.activeRevision.startDateTime",
 *     "activeRevision.event.activeRevision.endDateTime",
 *     "activeRevision.event.eventType.id",
 *     "activeRevision.event.eventType.activeRevision.name",
 *     "activeRevision.event.eventType.activeRevision.shortName",
 *     "activeRevision.event.eventType.activeRevision.slug",
 *     "activeRevision.event.eventType.activeRevision.color",
 *     "activeRevision.event.eventSeries.id",
 *     "activeRevision.event.eventSeries.name",
 *     "activeRevision.event.eventSeries.shortName",
 *     "activeRevision.event.eventSeries.slug",
 *     "activeRevision.event.eventSeries.color",
 *     "activeRevision.contact.id",
 *     "activeRevision.contact.contactName",
 *     "activeRevision.contact.sortableName",
 *     "activeRevision.contact.contactDetails.content",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 * @ApiFilter(SearchFilter::class, properties={
 *     "id": "iexact",
 *     "activeRevision.event.id": "iexact",
 *     "activeRevision.event.activeRevision.name": "ipartial",
 *     "activeRevision.event.activeRevision.shortName": "ipartial",
 *     "activeRevision.event.activeRevision.slug": "ipartial",
 *     "activeRevision.event.activeRevision.color": "ipartial",
 *     "activeRevision.event.activeRevision.startDateTime": "ipartial",
 *     "activeRevision.event.activeRevision.endDateTime": "ipartial",
 *     "activeRevision.event.eventType.id": "iexact",
 *     "activeRevision.event.eventType.activeRevision.name": "ipartial",
 *     "activeRevision.event.eventType.activeRevision.shortName": "ipartial",
 *     "activeRevision.event.eventType.activeRevision.slug": "ipartial",
 *     "activeRevision.event.eventType.activeRevision.color": "ipartial",
 *     "activeRevision.event.eventSeries.id": "iexact",
 *     "activeRevision.event.eventSeries.name": "ipartial",
 *     "activeRevision.event.eventSeries.shortName": "ipartial",
 *     "activeRevision.event.eventSeries.slug": "ipartial",
 *     "activeRevision.event.eventSeries.color": "ipartial",
 *     "activeRevision.contact.id": "iexact",
 *     "activeRevision.contact.contactName": "ipartial",
 *     "activeRevision.contact.contactDetails.content": "ipartial",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.name": "ipartial",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.shortName": "ipartial",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.slug": "ipartial"
 * })
 * @ApiFilter(ExistsFilter::class, properties={"deleted"})
 * @Searchable({
 *     "id",
 *     "activeRevision.event.id",
 *     "activeRevision.event.activeRevision.name",
 *     "activeRevision.event.activeRevision.shortName",
 *     "activeRevision.event.activeRevision.slug",
 *     "activeRevision.event.activeRevision.color",
 *     "activeRevision.event.activeRevision.startDateTime",
 *     "activeRevision.event.activeRevision.endDateTime",
 *     "activeRevision.event.eventType.id",
 *     "activeRevision.event.eventType.activeRevision.name",
 *     "activeRevision.event.eventType.activeRevision.shortName",
 *     "activeRevision.event.eventType.activeRevision.slug",
 *     "activeRevision.event.eventType.activeRevision.color",
 *     "activeRevision.event.eventSeries.id",
 *     "activeRevision.event.eventSeries.name",
 *     "activeRevision.event.eventSeries.shortName",
 *     "activeRevision.event.eventSeries.slug",
 *     "activeRevision.event.eventSeries.color",
 *     "activeRevision.contact.id",
 *     "activeRevision.contact.contactName",
 *     "activeRevision.contact.contactDetails.content",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipant extends AbstractRevisionContainer
{
    use BasicEntityTrait;
    use EntityDeletedContainerTrait;
    use BasicMailConfirmationTrait;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantRevision",
     *     mappedBy="container",
     *     cascade={"all"},
     *     orphanRemoval=true,
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected $revisions;

    /**
     * @var EventParticipantRevision
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantRevision",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="active_revision_id", referencedColumnName="id")
     */
    protected $activeRevision;

    /**
     * Type of relation between contact and event - attendee, staff....
     * @var EventParticipantType|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     inversedBy="eventParticipants",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     * @MaxDepth(1)
     */
    protected $eventParticipantType;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantNote",
     *     cascade={"all"},
     *     mappedBy="eventParticipant",
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected $eventParticipantNotes;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment",
     *     cascade={"all"},
     *     mappedBy="eventParticipant",
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected $eventParticipantPayments;

    /**
     * EventAttendee constructor.
     *
     * @param AbstractContact|null      $contact
     * @param Event|null                $event
     * @param EventParticipantType|null $eventParticipantType
     * @param Collection|null           $eventParticipantFlagConnections
     *
     * @param Collection|null           $eventParticipantNotes
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?AbstractContact $contact = null,
        ?Event $event = null,
        ?EventParticipantType $eventParticipantType = null,
        ?Collection $eventParticipantFlagConnections = null,
        ?Collection $eventParticipantNotes = null
    ) {
        $this->revisions = new ArrayCollection();
        $this->setEventParticipantType($eventParticipantType);
        $this->setEventParticipantNotes($eventParticipantNotes);
        $this->setEventParticipantPayments(new ArrayCollection());
        $this->addRevision(new EventParticipantRevision($contact, $event, $eventParticipantFlagConnections));
    }

    /**
     * @return string
     */
    public static function getRevisionClassName(): string
    {
        return EventParticipantRevision::class;
    }

    /**
     * @param AbstractRevision|null $revision
     */
    public static function checkRevision(?AbstractRevision $revision): void
    {
        assert($revision instanceof EventParticipantRevision);
    }

    /**
     * Remove notes where no content is present.
     */
    final public function removeEmptyEventParticipantNotes(): void
    {
        foreach ($this->getEventParticipantNotes() as $note) {
            assert($note instanceof EventParticipantNote);
            if (!$note->getTextValue() || '' === $note->getTextValue()) {
                $this->removeEventParticipantNote($note);
            }
        }
    }

    /**
     * @return Collection|null
     */
    final public function getEventParticipantNotes(): ?Collection
    {
        return $this->eventParticipantNotes;
    }

    /**
     * @param Collection|null $newEventParticipantNotes
     */
    final public function setEventParticipantNotes(?Collection $newEventParticipantNotes): void
    {
        if (!$this->eventParticipantNotes) {
            $this->eventParticipantNotes = new ArrayCollection();
        }
        if (!$newEventParticipantNotes) {
            $newEventParticipantNotes = new ArrayCollection();
        }
        foreach ($this->eventParticipantNotes as $oldEventParticipantNote) {
            if (!$newEventParticipantNotes->contains($oldEventParticipantNote)) {
                $this->removeEventParticipantNote($oldEventParticipantNote);
            }
        }
        if ($newEventParticipantNotes) {
            foreach ($newEventParticipantNotes as $newEventParticipantNote) {
                if (!$this->eventParticipantNotes->contains($newEventParticipantNote)) {
                    $this->addEventParticipantNote($newEventParticipantNote);
                }
            }
        }
    }

    /**
     * @param EventParticipantNote|null $eventParticipantNote
     */
    final public function removeEventParticipantNote(?EventParticipantNote $eventParticipantNote): void
    {
        if (!$eventParticipantNote) {
            return;
        }
        if ($this->eventParticipantNotes->removeElement($eventParticipantNote)) {
            $eventParticipantNote->setEventParticipant(null);
        }
    }

    /**
     * @param EventParticipantNote|null $eventParticipantNote
     */
    final public function addEventParticipantNote(?EventParticipantNote $eventParticipantNote): void
    {
        if ($eventParticipantNote && !$this->eventParticipantNotes->contains($eventParticipantNote)) {
            $this->eventParticipantNotes->add($eventParticipantNote);
            $eventParticipantNote->setEventParticipant($this);
        }
    }

    /**
     * @return EventParticipantType|null
     */
    final public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    /**
     * @param EventParticipantType|null $eventParticipantType
     */
    final public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        if ($this->eventParticipantType && $eventParticipantType !== $this->eventParticipantType) {
            $this->eventParticipantType->removeEventParticipant($this);
        }
        if ($eventParticipantType && $this->eventParticipantType !== $eventParticipantType) {
            $this->eventParticipantType = $eventParticipantType;
            $eventParticipantType->addEventParticipant($this);
        }
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return int
     * @throws PriceInvalidArgumentException
     * @throws RevisionMissingException
     */
    final public function getPriceRest(?DateTime $referenceDateTime = null): int
    {
        return $this->getPrice($referenceDateTime) - $this->getPriceDeposit($referenceDateTime);
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return int
     * @throws RevisionMissingException
     * @throws PriceInvalidArgumentException
     */
    final public function getPrice(?DateTime $referenceDateTime = null): int
    {
        return $this->getRevisionByDate($referenceDateTime)->getPrice();
    }

    /**
     * @param DateTime|null $dateTime
     *
     * @return EventParticipantRevision
     * @throws RevisionMissingException
     */
    final public function getRevisionByDate(?DateTime $dateTime = null): EventParticipantRevision
    {
        $revision = $this->getRevision($dateTime);
        assert($revision instanceof EventParticipantRevision);

        return $revision;
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return int
     * @throws PriceInvalidArgumentException
     * @throws RevisionMissingException
     */
    final public function getPriceDeposit(?DateTime $referenceDateTime = null): int
    {
        return $this->getRevisionByDate($referenceDateTime)->getDeposit();
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return int
     * @throws PriceInvalidArgumentException
     * @throws RevisionMissingException
     */
    final public function getRemainingPrice(?DateTime $referenceDateTime = null): int
    {
        return $this->getPrice($referenceDateTime) - $this->getPaidPrice();
    }

    /**
     * @return int
     */
    final public function getPaidPrice(): int
    {
        // TODO: Use referenceDateTime.
        $paid = 0;
        foreach ($this->getEventParticipantPayments() as $eventParticipantPayment) {
            assert($eventParticipantPayment instanceof EventParticipantPayment);
            $paid += $eventParticipantPayment->getNumericValue();
        }

        return $paid;
    }

    /**
     * @return Collection|null
     */
    final public function getEventParticipantPayments(): ?Collection
    {
        return $this->eventParticipantPayments;
    }

    /**
     * @param Collection|null $newEventParticipantPayments
     */
    final public function setEventParticipantPayments(?Collection $newEventParticipantPayments): void
    {
        if (!$this->eventParticipantPayments) {
            $this->eventParticipantPayments = new ArrayCollection();
        }
        if (!$newEventParticipantPayments) {
            $newEventParticipantPayments = new ArrayCollection();
        }
        foreach ($this->eventParticipantPayments as $oldEventParticipantPayment) {
            if (!$newEventParticipantPayments->contains($oldEventParticipantPayment)) {
                $this->removeEventParticipantPayment($oldEventParticipantPayment);
            }
        }
        if ($newEventParticipantPayments) {
            foreach ($newEventParticipantPayments as $newEventParticipantPayment) {
                if (!$this->eventParticipantPayments->contains($newEventParticipantPayment)) {
                    $this->addEventParticipantPayment($newEventParticipantPayment);
                }
            }
        }
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return float
     * @throws PriceInvalidArgumentException
     * @throws RevisionMissingException
     */
    final public function getPaidPricePercent(?DateTime $referenceDateTime = null): float
    {
        return !$this->getPrice($referenceDateTime) ? 0 : $this->getPaidPrice() / $this->getPrice($referenceDateTime);
    }

    /**
     * @param EventParticipantPayment|null $eventParticipantPayment
     */
    final public function removeEventParticipantPayment(?EventParticipantPayment $eventParticipantPayment): void
    {
        if (!$eventParticipantPayment) {
            return;
        }
        if ($this->eventParticipantPayments->removeElement($eventParticipantPayment)) {
            $eventParticipantPayment->setEventParticipant(null);
        }
    }

    /**
     * @param EventParticipantPayment|null $eventParticipantPayment
     */
    final public function addEventParticipantPayment(?EventParticipantPayment $eventParticipantPayment): void
    {
        if ($eventParticipantPayment && !$this->eventParticipantPayments->contains($eventParticipantPayment)) {
            $this->eventParticipantPayments->add($eventParticipantPayment);
            $eventParticipantPayment->setEventParticipant($this);
        }
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return int
     * @throws PriceInvalidArgumentException
     * @throws RevisionMissingException
     */
    final public function getRemainingDeposit(?DateTime $referenceDateTime = null): int
    {
        return $this->getPriceDeposit($referenceDateTime) - $this->getPaidPrice();
    }

    /**
     * @param Event|null $event
     *
     * @throws EventCapacityExceededException
     * @throws RevisionMissingException
     */
    final public function setEvent(?Event $event): void
    {
        if ($this->getEvent() !== $event) {
            $newRevision = clone $this->getRevisionByDate();
            $newRevision->setEvent($event);
            $this->addRevision($newRevision);
        }
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return Event|null
     * @throws RevisionMissingException
     */
    final public function getEvent(?DateTime $referenceDateTime = null): ?Event
    {
        return $this->getRevisionByDate($referenceDateTime)->getEvent();
    }

    /**
     * @param EventParticipantFlagConnection|null $eventParticipantFlagConnection
     *
     * @throws RevisionMissingException
     * @throws EventCapacityExceededException
     */
    final public function addEventParticipantFlagConnection(?EventParticipantFlagConnection $eventParticipantFlagConnection): void
    {
        if ($eventParticipantFlagConnection && !$this->getEventParticipantFlagConnections()->contains($eventParticipantFlagConnection)) {
            $newRevision = clone $this->getRevisionByDate();
            $newRevision->addEventParticipantFlagConnection($eventParticipantFlagConnection);
            $this->addRevision($newRevision);
        }
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return Collection
     */
    final public function getEventParticipantFlagConnections(?DateTime $referenceDateTime = null): Collection
    {
        try {
            return $this->getRevisionByDate($referenceDateTime)->getEventParticipantFlagConnections() ?? new ArrayCollection();
        } catch (Exception $e) {
            return new ArrayCollection();
        }
    }

    /**
     * @param AbstractContact|null $contact
     *
     * @throws RevisionMissingException
     */
    final public function setContact(?AbstractContact $contact): void
    {
        if ($this->getContact() !== $contact) {
            $newRevision = clone $this->getRevisionByDate();
            $newRevision->setContact($contact);
            $this->addRevision($newRevision);
        }
    }

    /**
     * @param DateTime|null $referenceDateTime
     *
     * @return AbstractContact
     * @throws RevisionMissingException
     */
    final public function getContact(?DateTime $referenceDateTime = null): AbstractContact
    {
        return $this->getRevisionByDate($referenceDateTime)->getContact();
    }

    /**
     * @return bool
     */
    final public function hasActivatedContactUser(): bool
    {
        try {
            $contact = $this->getContact();
            if ($contact && $contact->getAppUser() && $contact->getAppUser()->getAccountActivationDateTime()) {
                return true;
            }
            if ($contact instanceof Organization) {
                foreach ($contact->getContactPersons() as $contactPerson) {
                    assert($contactPerson instanceof Person);
                    if ($contactPerson->getAppUser() && $contactPerson->getAppUser()->getAccountActivationDateTime()) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /** @noinspection MethodShouldBeFinalInspection */
    /**
     * Get variable symbol of this eventParticipant (default is cropped phone number).
     * @return string|null
     */
    public function getVariableSymbol(): ?string
    {
        try {
            $phone = $this->getContact()->getPhone();
        } catch (RevisionMissingException $e) {
            return null;
        }
        $phone = preg_replace('/\s/', '', $phone);

        return substr(trim($phone), strlen(trim($phone)) - 9, 9);
    }
}
