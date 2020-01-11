<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_series")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventSeries
{
    use BasicEntityTrait;
    use NameableBasicTrait;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     mappedBy="eventSeries",
     *     cascade={"all"}
     * )
     */
    protected ?Collection $events = null;

    public function __construct(?Nameable $nameable = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->events = new ArrayCollection();
    }

    public function addEvent(?Event $event): void
    {
        if ($event && !$this->events->contains($event)) {
            $this->events->add($event);
            $event->setSeries($this);
        }
    }

    public function removeEvent(?Event $contact): void
    {
        if ($contact && $this->events->removeElement($contact)) {
            $contact->setSeries(null);
        }
    }

    public function getSeqId(Event $event): ?int
    {
        if (!$event->getType() || !$event->getStartDate() || !$event->isBatchOrYear()) {
            return null;
        }
        $seqId = 1;
        $events = $this->getEvents($event->getType()->getType(), ($event->isBatch() ? $event->getStartYear() : null), false);
        foreach ($events as $e) {
            if ($e instanceof Event && $e->getStartDate() && $e->getId() !== $event->getId() && $e->getStartDate() < $event->getStartDate()) {
                $seqId++;
            }
        }

        return $seqId;
    }

    public function getEvents(?string $eventTypeType = null, ?int $year = null, ?bool $deleted = true): Collection
    {
        $events = ($this->events ?? new ArrayCollection())->filter(fn(Event $e) => $deleted || !$e->isDeleted());
        if (null !== $eventTypeType) {
            $events = $events->filter(fn(Event $e) => $e->getType() && $eventTypeType === $e->getType()->getType());
        }
        if (null !== $year) {
            $events = $events->filter(fn(Event $e) => $e->getStartYear() && $year === $e->getStartYear());
        }

        return $events;
    }
}
