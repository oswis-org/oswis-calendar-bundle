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
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_attendee")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_attendees_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendees_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_attendee_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_delete"}}
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
     * DUMMY property for use in forms.
     * @var bool
     */
    public $selectedDummy;

    /**
     * DUMMY property for use in forms.
     * @var Collection
     */
    public $eventsDummy;

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
     *     inversedBy="eventContactRevisions",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventParticipantType;

    /**
     * EventAttendee constructor.
     *
     * @param AbstractContact|null $contact
     * @param Event|null           $event
     * @param Collection|null      $eventContactFlagConnections
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?AbstractContact $contact = null,
        ?Event $event = null,
        ?Collection $eventContactFlagConnections = null
    ) {
        $this->revisions = new ArrayCollection([new EventParticipantRevision($contact, $event, $eventContactFlagConnections)]);
        $this->eventsDummy = new ArrayCollection();
        /// set flags
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
     * @return AbstractContact
     * @throws RevisionMissingException
     */
    final public function getContact(?DateTime $referenceDateTime = null): AbstractContact
    {
        return $this->getRevisionByDate($referenceDateTime)->getContact();
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
     * @throws RevisionMissingException
     * @throws PriceInvalidArgumentException
     */
    final public function getPrice(?DateTime $referenceDateTime = null): int
    {
        return $this->getRevisionByDate($referenceDateTime)->getPrice();
    }

}
