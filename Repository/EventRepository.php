<?php

namespace Zakjakub\OswisCalendarBundle\Repository;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
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
        $subEvents = new ArrayCollection(
            $queryBuilder = $this->createQueryBuilder('event')->where('event.superEvent = :parent_id')->setParameter('parent_id', $parentEvent->getId())->getQuery()->getResult(Query::HYDRATE_OBJECT)
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
}
