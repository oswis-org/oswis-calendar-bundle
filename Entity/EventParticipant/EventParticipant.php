<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Zakjakub\OswisCoreBundle\Exceptions\RevisionMissingException;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

/**
 * Participation of contact in event.
 * @Doctrine\ORM\Mapping\Entity
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
 * @Searchable({
 *     "id",
 *     "student.person.fullName",
 *     "student.person.description",
 *     "student.person.note",
 *     "event.name",
 *     "event.description",
 *     "event.note"
 * })
 */
class EventParticipant extends AbstractRevisionContainer
{
    use BasicEntityTrait;

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
        $this->setEventParticipantType($eventParticipantType);
        $this->setEventParticipantNotes($eventParticipantNotes);
        $this->revisions = new ArrayCollection();
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
    final public function getPriceDeposit(?DateTime $referenceDateTime = null): int
    {
        return $this->getRevisionByDate($referenceDateTime)->getDeposit();
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
    final public function getPriceRest(?DateTime $referenceDateTime = null): int
    {
        return $this->getPrice($referenceDateTime) - $this->getPriceDeposit($referenceDateTime);
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

    final public function getPaidPrice(): int
    {
        return 0;
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
     * @param EventParticipantFlagConnection|null $eventParticipantFlagConnection
     *
     * @throws RevisionMissingException
     */
    final public function addEventParticipantFlagConnection(?EventParticipantFlagConnection $eventParticipantFlagConnection): void
    {
        $newRevision = clone $this->getRevisionByDate();
        $newRevision->addEventParticipantFlagConnection($eventParticipantFlagConnection);
        $this->addRevision($newRevision);
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



}
