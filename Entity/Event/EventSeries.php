<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_series")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventSeries implements NameableInterface
{
    use NameableTrait;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", mappedBy="series")
     */
    protected ?Collection $events = null;

    public function __construct(?Nameable $nameable = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->events = new ArrayCollection();
    }

    public function addEvent(?Event $event): void
    {
        if ($event && !$this->getEvents()->contains($event)) {
            $this->getEvents()->add($event);
            $event->setSeries($this);
        }
    }

    public function removeEvent(?Event $contact): void
    {
        if ($contact && $this->getEvents()->removeElement($contact)) {
            $contact->setSeries(null);
        }
    }

    public function getSeqId(Event $event): ?int
    {
        if (!$event->getType() || !$event->getStartDate() || !$event->isBatchOrYear()) {
            return null;
        }
        $seqId = 1;
        $events = $this->getEvents($event->getTypeString(), ($event->isBatch() ? $event->getStartYear() : null), false);
        foreach ($events as $e) {
            if ($e instanceof Event && $e->getStartDate() && $e->getId() !== $event->getId() && $e->getStartDate() < $event->getStartDate()) {
                $seqId++;
            }
        }

        return $seqId;
    }

    public function getEvents(?string $eventTypeString = null, ?int $year = null, bool $deleted = true): Collection
    {
        $events = $this->events ??= new ArrayCollection();
        if (!$deleted) {
            $events->filter(fn(Event $event) => !$event->isDeleted());
        }
        if (null !== $eventTypeString) {
            $events = $events->filter(fn(Event $e) => $e->getType() && $eventTypeString === $e->getTypeString());
        }
        if (null !== $year) {
            $events = $events->filter(fn(Event $e) => $e->getStartYear() && $year === $e->getStartYear());
        }

        return $events;
    }
}
