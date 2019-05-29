<?php

namespace Zakjakub\OswisCalendarBundle\Repository;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventRevision;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;

class EventRevisionRepository extends EntityRepository
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
        $subEvents = new ArrayCollection(
            $this->createQueryBuilder('event')
                ->where('event.superEvent = :parent_id')
                ->setParameter('parent_id', $parentEvent->getId())
                ->getQuery()
                ->getResult(Query::HYDRATE_OBJECT)
        );

        if ($onlyWithRegistrationAllowed) {
            $subEvents->filter(
                static function (Event $subEvent) use ($registrationsEventParticipantType, $referenceDateTime) {
                    return $subEvent->isRegistrationsAllowed($registrationsEventParticipantType, $referenceDateTime);
                }
            );
        }

        return $subEvents ?? new ArrayCollection();
    }

    /**
     * @param string        $slug
     *
     * @param DateTime|null $referenceDateTime
     *
     * @return EventRevision|null
     * @throws NonUniqueResultException
     */
    final public function findOneActiveBySlug(string $slug, ?DateTime $referenceDateTime = null): ?Event
    {
        $eventRevisions = new ArrayCollection(
            $this->createQueryBuilder('event_revision')
                ->where('event_revision.slug = :slug')
                ->setParameter('slug', $slug)
                ->getQuery()
                ->getResult(Query::HYDRATE_OBJECT)
        );

        $eventRevisions->filter(
            static function (EventRevision $eventRevision) use ($referenceDateTime) {
                return $eventRevision->isActive($referenceDateTime);
            }
        );

        if ($eventRevisions->count() === 1) {
            return $eventRevisions->first();
        }

        if ($eventRevisions->count() < 1) {
            return null;
        }

        throw new NonUniqueResultException('Nalezeno více událostí se zadaným identifikátorem'.($slug ? ' '.$slug : '').'.');
    }


}
