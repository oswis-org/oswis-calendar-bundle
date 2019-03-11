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
 * Event organizer (somebody who is involved in event organization).
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="Zakjakub\OswisCalendarBundle\Repository\EventOrganizerRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_organizer")
 * @ApiResource(
 *   attributes={
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_organizers_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_organizers_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_organizer_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_organizer_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_organizer_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "contact.name",
 *     "contact.description",
 *     "contact.note",
 *     "event.name",
 *     "event.description",
 *     "event.note"
 * })
 */
class EventOrganizer
{
    use BasicEntityTrait;

    /**
     * Person or organization.
     * @var AbstractContact|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact",
     *     cascade={"all"},
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
     *     inversedBy="eventOrganizers",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $event;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventOrganizerFlagConnection",
     *     cascade={"all"},
     *     mappedBy="eventOrganizer",
     *     fetch="EAGER"
     * )
     */
    protected $flags;

    public function __construct(
        ?AbstractContact $employer = null,
        ?Event $event = null
    ) {
        $this->flags = new ArrayCollection();
        $this->setContact($employer);
        $this->setEvent($event);
    }

    final public function getFlags(): Collection
    {
        return $this->flags ?? new ArrayCollection();
    }

    final public function addFlag(?EventOrganizerFlagConnection $flagInEmployer): void
    {
        if ($flagInEmployer && !$this->flags->contains($flagInEmployer)) {
            $this->flags->add($flagInEmployer);
            $flagInEmployer->setEventOrganizer($this);
        }
    }

    final public function removeFlag(?EventOrganizerFlagConnection $flagInEmployer): void
    {
        if (!$flagInEmployer) {
            return;
        }
        if ($this->flags->removeElement($flagInEmployer)) {
            $flagInEmployer->setEventOrganizer(null);
        }
    }

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventOrganizer($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventOrganizer($this);
        }
    }

    final public function getContact(): ?AbstractContact
    {
        return $this->contact;
    }

    final public function setContact(?AbstractContact $contact): void
    {
        if ($contact !== $this->contact) {
            $this->contact = $contact;
        }
    }
}
