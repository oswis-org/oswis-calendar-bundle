<?php

namespace Zakjakub\OswisCalendarBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Zakjakub\OswisCalendarBundle\Entity\EventOrganizer;

class EventOrganizerRepository extends EntityRepository
{

    /**
     * @param int    $contactId
     * @param string $eventSlug
     *
     * @return EventOrganizer|null
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    final public function findOneEventOrganizer(int $contactId, string $eventSlug): ?EventOrganizer
    {
        return $this->createQueryBuilder('job_fair_event_organizer')
            ->join('job_fair_event_organizer.event', 'event')
            ->join('job_fair_event_organizer.contact', 'contact')
            ->andWhere('event.slug = :slug')
            ->andWhere('contact.id = :contactId')
            ->setParameter('slug', $eventSlug)
            ->setParameter('contactId', $contactId)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_OBJECT);
    }

}
