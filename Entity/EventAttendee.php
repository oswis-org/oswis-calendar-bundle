<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_attendee")
 * @ApiResource(
 *   attributes={
 *     "access_control"="is_granted('ROLE_CUSTOMER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_attendees_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_CUSTOMER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendees_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_CUSTOMER')",
 *       "normalization_context"={"groups"={"calendar_event_attendee_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_CUSTOMER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
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
class EventAttendee
{
    use BasicEntityTrait;

    /**
     * Person or organization.
     * @var AbstractContact|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $contact;

    /**
     * Event.
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
     *     inversedBy="studentsEvents",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $event;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlagConnection",
     *     cascade={"all"},
     *     mappedBy="eventAttendee",
     *     fetch="EAGER"
     * )
     */
    protected $eventAttendeeFlagConnections;

    /**
     * EventAttendee constructor.
     *
     * @param AbstractContact|null $contact
     * @param Event|null           $event
     */
    public function __construct(
        ?AbstractContact $contact,
        ?Event $event
    ) {
        $this->setContact($contact);
        $this->setEvent($event);
    }

    final public function getEventAttendeeFlagConnections(): Collection
    {
        return $this->eventAttendeeFlagConnections ?? new ArrayCollection();
    }

    final public function addEventAttendeeFlagConnection(?EventAttendeeFlagConnection $flag): void
    {
        if ($flag && !$this->eventAttendeeFlagConnections->contains($flag)) {
            $this->eventAttendeeFlagConnections->add($flag);
            $flag->setEventAttendee($this);
        }
    }

    final public function removeEventAttendeeFlagConnection(?EventAttendeeFlagConnection $flag): void
    {
        if (!$flag) {
            return;
        }
        if ($this->eventAttendeeFlagConnections->removeElement($flag)) {
            $flag->setEventAttendee(null);
        }
    }

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    final public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    final public function getContact(): ?AbstractContact
    {
        return $this->contact;
    }

    final public function setContact(?AbstractContact $contact): void
    {
        $this->contact = $contact;
    }
}
