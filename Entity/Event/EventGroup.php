<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Serializer\Annotation\MaxDepth;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_event_groups_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_event_groups_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_event_group_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Put(
            denormalizationContext: ['groups' => ['entity_put', 'calendar_event_group_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Delete(
            denormalizationContext: ['groups' => ['calendar_event_group_delete'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
    ],
    filters: ['search'],
    security: "is_granted('ROLE_MANAGER')"
)]
#[Entity]
#[Table(name: 'calendar_event_group')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class EventGroup implements NameableInterface
{
    use NameableTrait;

    #[OneToMany(targetEntity: Event::class, mappedBy: 'group')]
    #[MaxDepth(1)]
    protected ?Collection $events = null;

    public function __construct(?Nameable $nameable = null)
    {
        $this->events = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
    }

    public function addEvent(?Event $event): void
    {
        if ($event && !$this->getEvents()->contains($event)) {
            $this->getEvents()->add($event);
            $event->setGroup($this);
        }
    }

    public function getEvents(?string $eventType = null, ?int $year = null, bool $deleted = true): Collection
    {
        $events = $this->events ??= new ArrayCollection();
        if (!$deleted) {
            $events = $events->filter(fn (mixed $event) => $event instanceof Event && !$event->isDeleted());
        }
        if (null !== $eventType) {
            $events = $events->filter(fn (mixed $event) => $event instanceof Event && $event->getType() === $eventType);
        }
        if (null !== $year) {
            $events = $events->filter(fn (mixed $event) => $event instanceof Event && $event->getStartYear()
                && $year === $event->getStartYear(),);
        }

        return $events;
    }

    public function removeEvent(?Event $contact): void
    {
        if ($contact && $this->getEvents()->removeElement($contact)) {
            $contact->setGroup(null);
        }
    }

    public function getSeqId(Event $event): ?int
    {
        if (!$event->getCategory() || !$event->getStartDate() || !$event->isBatchOrYear()) {
            return null;
        }
        $seqId = 1;
        $events = $this->getEvents($event->getType(), ($event->isBatch() ? $event->getStartYear() : null), false);
        foreach ($events as $e) {
            if ($e instanceof Event && $e->getStartDate() && $e->getId() !== $event->getId()
                && $e->getStartDate() < $event->getStartDate()) {
                $seqId++;
            }
        }

        return $seqId;
    }
}
