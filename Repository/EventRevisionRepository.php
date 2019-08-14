<?php

namespace Zakjakub\OswisCalendarBundle\Repository;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventRevision;

class EventRevisionRepository extends EntityRepository
{

    /**
     * @param string        $slug
     *
     * @param DateTime|null $referenceDateTime
     *
     * @return EventRevision|null
     * @throws NonUniqueResultException
     */
    final public function findOneActiveBySlug(string $slug, ?DateTime $referenceDateTime = null): ?EventRevision
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
