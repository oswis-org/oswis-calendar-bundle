<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
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
 *       "normalization_context"={"groups"={"calendar_event_participants_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participants_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @ApiFilter(SearchFilter::class, properties={
 *     "id": "exact",
 *     "activeRevision.event.id": "exact",
 *     "activeRevision.contact.id": "exact"
 * })
 * @ApiFilter(ExistsFilter::class, properties={"deleted"})
 * @Searchable({
 *     "id",
 *     "activeRevision.event.activeRevision.name",
 *     "activeRevision.event.activeRevision.shortName",
 *     "activeRevision.event.activeRevision.slug",
 *     "activeRevision.contact.id",
 *     "activeRevision.contact.contactDetails.content",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "activeRevision.eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 */
class EventParticipant extends AbstractRevisionContainer
{
    use BasicEntityTrait;
    use EntityDeletedContainerTrait;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantRevision",
     *     mappedBy="container",
     *     cascade={"all"},
     *     orphanRemoval=true,
     *     fetch="EAGER"
     * )
     */
    protected $revisions;

    /**
     * @var EventParticipantRevision
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantRevision")
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

    final public function removeEventParticipantNote(?EventParticipantNote $eventParticipantNote): void
    {
        if (!$eventParticipantNote) {
            return;
        }
        if ($this->eventParticipantNotes->removeElement($eventParticipantNote)) {
            $eventParticipantNote->setEventParticipant(null);
        }
    }

    final public function addEventParticipantNote(?EventParticipantNote $eventParticipantNote): void
    {
        if ($eventParticipantNote && !$this->eventParticipantNotes->contains($eventParticipantNote)) {
            $this->eventParticipantNotes->add($eventParticipantNote);
            $eventParticipantNote->setEventParticipant($this);
        }
    }

    final public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

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

    final public function getPaidPrice(): int
    {
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

    final public function removeEventParticipantPayment(?EventParticipantPayment $eventParticipantPayment): void
    {
        if (!$eventParticipantPayment) {
            return;
        }
        if ($this->eventParticipantPayments->removeElement($eventParticipantPayment)) {
            $eventParticipantPayment->setEventParticipant(null);
        }
    }

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
