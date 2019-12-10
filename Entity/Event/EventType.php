<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use InvalidArgumentException;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TypeTrait;

/**
 * Type of Event.
 *
 * @Doctrine\ORM\Mapping\Entity()
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
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventType
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use ColorTrait;
    use TypeTrait;

    public const YEAR_OF_EVENT = 'year-of-event';
    public const BATCH_OF_EVENT = 'batch-of-event';
    public const LECTURE = 'lecture';
    public const WORKSHOP = 'workshop';
    public const MODERATED_DISCUSSION = 'moderated-discussion';
    public const TRANSPORT = 'transport';
    public const TEAM_BUILDING_STAY = 'team-building-stay';
    public const TEAM_BUILDING = 'team-building';

    /**
     * Events of that type.
     * @var Collection|null $event
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     mappedBy="eventType",
     *     fetch="EAGER",
     *     cascade={"all"}
     * )
     */
    protected ?Collection $events = null;

    /**
     * ContactDetailType constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null   $type
     * @param string|null   $color
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?string $type = null,
        ?string $color = null
    ) {
        $this->events = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
        $this->setColor($color);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            self::YEAR_OF_EVENT,
            self::BATCH_OF_EVENT,
            self::LECTURE,
            self::WORKSHOP,
            self::MODERATED_DISCUSSION,
            self::TRANSPORT,
            self::TEAM_BUILDING_STAY,
            self::TEAM_BUILDING,
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    final public function addEvent(?Event $event): void
    {
        if ($event && !$this->events->contains($event)) {
            $this->events->add($event);
            $event->setEventType($this);
        }
    }

    final public function removeEvent(?Event $event): void
    {
        if ($event && $this->events->removeElement($event)) {
            $event->setEventType(null);
        }
    }

    /**
     * @return Collection
     */
    final public function getEvents(): Collection
    {
        return $this->events;
    }

    final public function destroyRevisions(): void
    {
//        try {
//            $this->setFieldsFromNameable($this->getRevisionByDate()->getNameable());
//            $this->setColor($this->getRevisionByDate()->getColor());
//            $this->setType($this->getRevisionByDate()->getType());
//            foreach ($this->getRevisions() as $revision) {
//                assert($revision instanceof EventTypeRevision);
//                $this->removeRevision($revision);
//            }
//            $this->setActiveRevision(null);
//        } catch (RevisionMissingException $e) {
//        } catch (InvalidArgumentException $e) {
//        }
    }
}
