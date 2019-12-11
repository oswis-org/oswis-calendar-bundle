<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Repository;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
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
    final public function findEventsByType(EventType $type): Collection
    {
        $qb = $this->createQueryBuilder('e')->where('e.eventType = :type')->setParameter('type', $type->getId());

        return new ArrayCollection($qb->getQuery()->getResult(Query::HYDRATE_OBJECT));
    }

    /**
     * @param string    $type
     * @param bool|null $participantsLazyLoad
     * @param bool|null $partial
     *
     * @return Collection
     */
    final public function findEventsByTypeSlug(string $type, ?bool $participantsLazyLoad = false, ?bool $partial = false): Collection
    {
        $queryBuilder = $this->createQueryBuilder('e');
        $queryBuilder->innerJoin('e.eventType', 't', Join::WITH, 't.slug = :t')->setParameter('t', $type);
        $queryBuilder->setCacheable(false);
        $query = $queryBuilder->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setCacheable(false);
        $query->setFetchMode(
            Event::class,
            'eventParticipants',
            $participantsLazyLoad ? ClassMetadata::FETCH_EXTRA_LAZY : ClassMetadata::FETCH_EAGER
        );
        if ($partial) {
            $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, $partial);
        }

        return new ArrayCollection($query->getResult(Query::HYDRATE_OBJECT));
    }

    /**
     * @param string    $id
     * @param bool|null $participantsLazyLoad
     * @param bool|null $partial
     *
     * @return Event|null
     * @throws NonUniqueResultException
     */
    final public function findOneById(string $id, ?bool $participantsLazyLoad = false, ?bool $partial = false): ?Event
    {
        $queryBuilder = $this->createQueryBuilder('e')->where('e.id = :id')->setParameter('id', $id);
        $queryBuilder->setCacheable(false);
        $query = $queryBuilder->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setCacheable(false);
        $query->setFetchMode(
            Event::class,
            'eventParticipants',
            $participantsLazyLoad ? ClassMetadata::FETCH_EXTRA_LAZY : ClassMetadata::FETCH_EAGER
        );
        if ($partial) {
            $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, $partial);
        }

        return $query->getOneOrNullResult(Query::HYDRATE_OBJECT);
    }

    /**
     * Get sub events of event. Works only for depth == 0 (direct sub event).
     *
     * @param EventSeries $series
     *
     * @return Collection
     */
    final public function findEventsBySeries(EventSeries $series): Collection
    {
        return new ArrayCollection(
            $this->createQueryBuilder('e')->where('e.eventSeries = :s')->setParameter('s', $series->getId())->getQuery()->getResult(Query::HYDRATE_OBJECT)
        );
    }
}
