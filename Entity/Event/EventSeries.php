<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_series")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_series_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_series_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_series_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_series_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_series_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "description",
 *     "note"
 * })
 */
class EventSeries
{

    use BasicEntityTrait;
    use NameableBasicTrait;

    /**
     * @var Collection|null $events
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     mappedBy="eventSeries",
     *     cascade={"all"}
     * )
     */
    protected $events;

    /**
     * @param Nameable|null $nameable
     */
    public function __construct(
        ?Nameable $nameable = null
    ) {
        $this->setFieldsFromNameable($nameable);
        $this->events = new ArrayCollection();
    }

    /**
     * @param Event|null $event
     */
    final public function addEvent(?Event $event): void
    {
        if ($event && !$this->events->contains($event)) {
            $this->events->add($event);
            $event->setEventSeries($this);
        }
    }

    /**
     * @param Event|null $contact
     */
    final public function removeEvent(?Event $contact): void
    {
        if ($contact && $this->events->removeElement($contact)) {
            $contact->setEventSeries(null);
        }
    }

    /**
     * @return Collection
     */
    final public function getEvents(): Collection
    {
        return $this->events;
    }

}
