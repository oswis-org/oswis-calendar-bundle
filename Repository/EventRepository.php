<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Exception;
use OswisOrg\OswisAddressBookBundle\Entity\Place;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventSeries;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventType;

class EventRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_SLUG = 'slug';
    public const CRITERIA_EVENT_TYPE = 'type';
    public const CRITERIA_EVENT_TYPE_OF_TYPE = 'typeOfType';
    public const CRITERIA_EVENT_SERIES = 'series';
    public const CRITERIA_SUPER_EVENT = 'superEvent';
    public const CRITERIA_SUPER_EVENT_DEPTH = 'superEventDepth';
    public const CRITERIA_ONLY_ROOT = 'onlyRoot';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';
    public const CRITERIA_LOCATION = 'location';
    public const CRITERIA_START = 'start';
    public const CRITERIA_END = 'end';
    public const CRITERIA_ONLY_WITHOUT_DATE = 'onlyWithoutDate';
    public const CRITERIA_ONLY_PUBLIC_ON_WEB = 'onlyPublicOnWeb';
    public const CRITERIA_ONLY_PUBLIC_ON_WEB_ROUTE = 'onlyPublicOnWebRoute';

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
        $this->addSuperEventQuery($queryBuilder, $opts);
        $this->addIdQuery($queryBuilder, $opts);
        $this->addSlugQuery($queryBuilder, $opts);
        $this->addStartQuery($queryBuilder, $opts);
        $this->addEndQuery($queryBuilder, $opts);
        $this->addIncludeDeletedQuery($queryBuilder, $opts);
        $this->addOnlyRootQuery($queryBuilder, $opts);
        $this->addOnlyWithoutDateQuery($queryBuilder, $opts);
        $this->addSeriesQuery($queryBuilder, $opts);
        $this->addLocationQuery($queryBuilder, $opts);
        $this->addOnlyPublicOnWebQuery($queryBuilder, $opts);
        $this->addOnlyPublicOnWebRouteQuery($queryBuilder, $opts);
        $this->addTypeOfTypeQuery($queryBuilder, $opts);
        $this->addTypeQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder, true);

        return $queryBuilder;
    }

    public function getEvents(?array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        return new ArrayCollection($this->getEventsQueryBuilder($opts, $limit, $offset)->getQuery()->getResult());
    }

    private function addSuperEventQuery(QueryBuilder $queryBuilder, array $opts = []): void
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

    private function addIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' e.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function addSlugQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_SLUG])) {
            $queryBuilder->andWhere(' e.slug = :slug ')->setParameter('slug', $opts[self::CRITERIA_SLUG]);
        }
    }

    private function addStartQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_START])) {
            $startQuery = ' (e.startDateTime IS NULL) OR (:start < e.startDateTime) ';
            $queryBuilder->andWhere($startQuery)->setParameter('start', $opts[self::CRITERIA_START]);
        }
    }

    private function addEndQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_END])) {
            $endQuery = ' (e.endDateTime IS NULL) OR (:end > e.endDateTime) ';
            $queryBuilder->andWhere($endQuery)->setParameter('end', $opts[self::CRITERIA_END]);
        }
    }

    private function addIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('e.deleted IS NULL');
        }
    }

    private function addOnlyRootQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_ROOT])) {
            $queryBuilder->andWhere('e.superEvent IS NULL');
        }
    }

    private function addOnlyWithoutDateQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_WITHOUT_DATE])) {
            $queryBuilder->andWhere('e.startDateTime IS NULL');
            $queryBuilder->andWhere('e.endDateTime IS NULL');
        }
    }

    private function addSeriesQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_EVENT_SERIES]) && $opts[self::CRITERIA_END] instanceof EventSeries) {
            $queryBuilder->andWhere('e.series = :series_id');
            $queryBuilder->setParameter('series_id', $opts[self::CRITERIA_EVENT_SERIES]->getId());
        }
    }

    private function addLocationQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_LOCATION]) && $opts[self::CRITERIA_END] instanceof Place) {
            $queryBuilder->andWhere('e.location = :location_id')->setParameter('location_id', $opts[self::CRITERIA_LOCATION]->getId());
        }
    }

    private function addOnlyPublicOnWebQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_PUBLIC_ON_WEB])) {
            $queryBuilder->andWhere('e.publicOnWeb = true');
        }
    }

    private function addOnlyPublicOnWebRouteQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_PUBLIC_ON_WEB_ROUTE])) {
            $queryBuilder->andWhere('e.publicOnWebRoute = true');
        }
    }

    private function addTypeOfTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_EVENT_TYPE_OF_TYPE]) && is_string($opts[self::CRITERIA_EVENT_TYPE_OF_TYPE])) {
            $queryBuilder->leftJoin('e.type', 'type');
            $queryBuilder->andWhere('type.type = :type_of_type');
            $queryBuilder->setParameter('type_of_type', $opts[self::CRITERIA_EVENT_TYPE_OF_TYPE]);
        }
    }

    private function addTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_EVENT_TYPE]) && $opts[self::CRITERIA_EVENT_TYPE] instanceof EventType) {
            $queryBuilder->andWhere('e.type = :type_id');
            $queryBuilder->setParameter('type_id', $opts[self::CRITERIA_EVENT_TYPE]->getId());
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
}
