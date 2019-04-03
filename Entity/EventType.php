<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;

/**
 * Class ContactType
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_type")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_types_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_types_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_type_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_type_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_type_delete"}}
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
class EventType
{

    use BasicEntityTrait;
    use NameableBasicTrait;
    use ColorTrait;

    /**
     * @var Collection|null $events Events of this type
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
     *     mappedBy="eventType",
     *     cascade={"all"}
     * )
     */
    protected $events;

    /**
     * ContactDetailType constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null   $color
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?string $color = null
    ) {
        $this->events = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setColor($color);
    }

    final public function addEvent(?Event $contact): void
    {
        if ($contact && !$this->events->contains($contact)) {
            $this->events->add($contact);
            $contact->setEventType($this);
        }
    }

    final public function removeEvent(?Event $contact): void
    {
        if ($contact && $this->events->removeElement($contact)) {
            $contact->setEventType(null);
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
