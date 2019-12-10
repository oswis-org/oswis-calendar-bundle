<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Repository;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventType;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;

class EventRepository extends EntityRepository
{

    /**
     * Get sub events of event. Works only for depth == 0 (direct sub event).
     *
     * @param Event                     $parentEvent
     * @param bool|null                 $onlyWithRegistrationAllowed
     * @param EventParticipantType|null $registrationsEventParticipantType
     * @param DateTime|null             $referenceDateTime
     * @param int|null                  $depth
     *
     * @return Collection
     */
    final public function findSubEventsByParent(
        Event $parentEvent,
        ?bool $onlyWithRegistrationAllowed = null,
        ?EventParticipantType $registrationsEventParticipantType = null,
        ?DateTime $referenceDateTime = null,
        ?int $depth = 0
    ): Collection {
        $subEventsAsArray = $this->createQueryBuilder('event')->where('event.superEvent = :parent_id')->setParameter('parent_id', $parentEvent->getId())->getQuery()->getResult(Query::HYDRATE_OBJECT);
        $subEvents = new ArrayCollection($subEventsAsArray);
        if ($onlyWithRegistrationAllowed) {
            return $subEvents->filter(fn(Event $subEvent) => $subEvent->isRegistrationsAllowed($registrationsEventParticipantType, $referenceDateTime));
        }

        return $subEvents ?? new ArrayCollection();
    }

    /**
     * Get sub events of event. Works only for depth == 0 (direct sub event).
     *
     * @param EventType $type
     *
     * @return Collection
     */
    final public function findEventsByType(
        EventType $type
    ): Collection {
        return new ArrayCollection(
            $this->createQueryBuilder('e')->where('e.eventType = :type')->setParameter('type', $type->getId())->getQuery()->getResult(Query::HYDRATE_OBJECT)
        );
    }

    /**
     * Get sub events of event. Works only for depth == 0 (direct sub event).
     *
     * @param EventSeries $series
     *
     * @return Collection
     */
    final public function findEventsBySeries(
        EventSeries $series
    ): Collection {
        return new ArrayCollection(
            $this->createQueryBuilder('e')->where('e.eventSeries = :series')->setParameter('series', $series->getId())->getQuery()->getResult(Query::HYDRATE_OBJECT)
        );
    }
}
