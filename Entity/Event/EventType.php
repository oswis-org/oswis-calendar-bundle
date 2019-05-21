<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Exceptions\RevisionMissingException;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorContainerTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicContainerTrait;

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
class EventType extends AbstractRevisionContainer
{

    use BasicEntityTrait;
    use NameableBasicContainerTrait;
    use ColorContainerTrait;

    /**
     * @var Collection
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventTypeRevision",
     *     mappedBy="container",
     *     cascade={"all"},
     *     orphanRemoval=true,
     *     fetch="EAGER"
     * )
     */
    protected $revisions;

    /**
     * Event revisions of that type.
     * @var Collection|null $eventRevisions EventRevisions of this event type.
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRevision",
     *     mappedBy="eventType",
     *     cascade={"all"}
     * )
     */
    protected $eventRevisions;

    /**
     * ContactDetailType constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null   $type
     * @param string|null   $color
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?string $type = null,
        ?string $color = null
    ) {
        $this->eventRevisions = new ArrayCollection([new EventTypeRevision($nameable, $type, $color)]);
    }

    /**
     * @return string
     */
    public static function getRevisionClassName(): string
    {
        return EventTypeRevision::class;
    }

    /**
     * @param AbstractRevision|null $revision
     */
    public static function checkRevision(?AbstractRevision $revision): void
    {
        assert($revision instanceof EventTypeRevision);
    }

    final public function addEvent(?EventRevision $contact): void
    {
        if ($contact && !$this->eventRevisions->contains($contact)) {
            $this->eventRevisions->add($contact);
            $contact->setEventType($this);
        }
    }

    final public function removeEvent(?EventRevision $contact): void
    {
        if ($contact && $this->eventRevisions->removeElement($contact)) {
            $contact->setEventType(null);
        }
    }

    /**
     * @return Collection
     */
    final public function getEventRevisions(): Collection
    {
        return $this->eventRevisions;
    }

    /**
     * @param DateTime|null $dateTime
     *
     * @return EventTypeRevision
     * @throws RevisionMissingException
     */
    final public function getRevisionByDate(?DateTime $dateTime = null): EventTypeRevision
    {
        $revision = $this->getRevision($dateTime);
        assert($revision instanceof EventTypeRevision);

        return $revision;
    }


}
