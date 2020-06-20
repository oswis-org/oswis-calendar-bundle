<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use LogicException;
use OswisOrg\OswisAddressBookBundle\Entity\Place;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventGroup;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantEMail\ParticipantEMailCategory;

class EventRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     *
     * @throws LogicException
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }


    public const CRITERIA_ID = 'id';
    public const CRITERIA_SLUG = 'slug';
    public const CRITERIA_TYPE = 'type';
    public const CRITERIA_TYPE_STRING = 'typeString';
    public const CRITERIA_SERIES = 'series';
    public const CRITERIA_SUPER_EVENT = 'superEvent';
    public const CRITERIA_SUPER_EVENT_DEPTH = 'superEventDepth';
    public const CRITERIA_ONLY_ROOT = 'onlyRoot';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';
    public const CRITERIA_LOCATION = 'location';
    public const CRITERIA_START = 'start';
    public const CRITERIA_END = 'end';
    public const CRITERIA_ONLY_WITHOUT_DATE = 'onlyWithoutDate';
    public const CRITERIA_ONLY_PUBLIC_ON_WEB = 'onlyPublicOnWeb';

    public function findOneBy(array $criteria, array $orderBy = null): ?Event
    {
        $event = parent::findOneBy($criteria, $orderBy);

        return $event instanceof Event ? $event : null;
    }

    public function getEvent(?array $opts = []): ?Event
    {
        try {
            $event = $this->getEventsQueryBuilder($opts)->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $event instanceof Event ? $event : null;
    }

    public function getEventsQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('e');
        $this->setSuperEventQuery($queryBuilder, $opts);
        $this->setIdQuery($queryBuilder, $opts);
        $this->setSlugQuery($queryBuilder, $opts);
        $this->setStartQuery($queryBuilder, $opts);
        $this->setEndQuery($queryBuilder, $opts);
        $this->setIncludeDeletedQuery($queryBuilder, $opts);
        $this->setOnlyRootQuery($queryBuilder, $opts);
        $this->setOnlyWithoutDateQuery($queryBuilder, $opts);
        $this->setSeriesQuery($queryBuilder, $opts);
        $this->setLocationQuery($queryBuilder, $opts);
        $this->setOnlyPublicOnWebQuery($queryBuilder, $opts);
        $this->setTypeStringQuery($queryBuilder, $opts);
        $this->addTypeQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder, true);

        return $queryBuilder;
    }

    private function setSuperEventQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_SUPER_EVENT]) && $opts[self::CRITERIA_SUPER_EVENT] instanceof Event) {
            $eventQuery = ' e.superEvent = :super_event_id ';
            $queryBuilder->leftJoin('e.superEvent', 'e0');
            $superEventDepth = !empty($opts[self::CRITERIA_SUPER_EVENT_DEPTH]) ? (int)$opts[self::CRITERIA_SUPER_EVENT_DEPTH] : 0;
            for ($i = 0; $i < $superEventDepth; $i++) {
                $j = $i + 1;
                $queryBuilder->leftJoin("e$i.superEvent", "e$j");
                $eventQuery .= " OR e$j = :event_id ";
            }
            $queryBuilder->andWhere($eventQuery)->setParameter('super_event_id', $opts[self::CRITERIA_SUPER_EVENT]->getId());
        }
    }

    private function setIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' e.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function setSlugQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_SLUG])) {
            $queryBuilder->andWhere(' e.slug = :slug ')->setParameter('slug', $opts[self::CRITERIA_SLUG]);
        }
    }

    private function setStartQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_START])) {
            $startQuery = ' (e.startDateTime IS NULL) OR (:start < e.startDateTime) ';
            $queryBuilder->andWhere($startQuery)->setParameter('start', $opts[self::CRITERIA_START]);
        }
    }

    private function setEndQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_END])) {
            $endQuery = ' (e.endDateTime IS NULL) OR (:end > e.endDateTime) ';
            $queryBuilder->andWhere($endQuery)->setParameter('end', $opts[self::CRITERIA_END]);
        }
    }

    private function setIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('e.deleted IS NULL');
        }
    }

    private function setOnlyRootQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_ROOT])) {
            $queryBuilder->andWhere('e.superEvent IS NULL');
        }
    }

    private function setOnlyWithoutDateQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_WITHOUT_DATE])) {
            $queryBuilder->andWhere('e.startDateTime IS NULL');
            $queryBuilder->andWhere('e.endDateTime IS NULL');
        }
    }

    private function setSeriesQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_SERIES]) && $opts[self::CRITERIA_END] instanceof EventGroup) {
            $queryBuilder->andWhere('e.series = :series_id');
            $queryBuilder->setParameter('series_id', $opts[self::CRITERIA_SERIES]->getId());
        }
    }

    private function setLocationQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_LOCATION]) && $opts[self::CRITERIA_END] instanceof Place) {
            $queryBuilder->andWhere('e.location = :location_id')->setParameter('location_id', $opts[self::CRITERIA_LOCATION]->getId());
        }
    }

    private function setOnlyPublicOnWebQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_PUBLIC_ON_WEB])) {
            $queryBuilder->andWhere('e.publicOnWeb = true');
        }
    }

    private function setTypeStringQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_TYPE_STRING]) && is_string($opts[self::CRITERIA_TYPE_STRING])) {
            $queryBuilder->leftJoin('e.type', 'type');
            $queryBuilder->andWhere('type.type = :type_of_type');
            $queryBuilder->setParameter('type_of_type', $opts[self::CRITERIA_TYPE_STRING]);
        }
    }

    private function addTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_TYPE]) && $opts[self::CRITERIA_TYPE] instanceof EventCategory) {
            $queryBuilder->andWhere('e.type = :type_id');
            $queryBuilder->setParameter('type_id', $opts[self::CRITERIA_TYPE]->getId());
        }
    }

    private function addLimit(QueryBuilder $queryBuilder, ?int $limit = null, ?int $offset = null): void
    {
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }
        if (null !== $offset) {
            $queryBuilder->setFirstResult($offset);
        }
    }

    private function addOrderBy(QueryBuilder $queryBuilder, bool $dateTime = true, bool $name = true): void
    {
        if ($dateTime) {
            $queryBuilder->addOrderBy('e.startDateTime', 'DESC');
            $queryBuilder->addOrderBy('e.endDateTime', 'DESC');
        }
        if ($name) {
            $queryBuilder->addOrderBy('e.name', 'ASC');
        }
        $queryBuilder->addOrderBy('e.id', 'ASC');
    }

    public function getEvents(?array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        return new ArrayCollection(
            $this->getEventsQueryBuilder($opts, $limit, $offset)->getQuery()->getResult()
        );
    }
}
