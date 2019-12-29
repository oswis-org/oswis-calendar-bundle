<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventType;

class EventRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_SLUG = 'slug';
    public const CRITERIA_EVENT_TYPE = 'eventType';
    public const CRITERIA_EVENT_TYPE_OF_TYPE = 'eventTypeOfType';
    public const CRITERIA_EVENT_SERIES = 'eventSeries';
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
            $event = $this->getEventsQueryBuilder($opts, 1)->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $event instanceof Event ? $event : null;
    }

    public function getEventsQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('e');
        if (!empty($opts[self::CRITERIA_SUPER_EVENT]) && $opts[self::CRITERIA_SUPER_EVENT] instanceof Event) {
            $eventQuery = ' e.superEvent = :super_event_id ';
            $queryBuilder->leftJoin('e.superEvent', 'e0');
            $superEventDepth = !empty($opts[self::CRITERIA_SUPER_EVENT]) ? $opts[self::CRITERIA_SUPER_EVENT] : 0;
            for ($i = 0; $i < $superEventDepth; $i++) {
                $j = $i + 1;
                $queryBuilder->leftJoin("e$i.superEvent", "e$j");
                $eventQuery .= " OR e$j = :event_id ";
            }
            $queryBuilder->andWhere($eventQuery)->setParameter('super_event_id', $opts[self::CRITERIA_SUPER_EVENT]->getId());
        }
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' e.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
        if (!empty($opts[self::CRITERIA_SLUG])) {
            $queryBuilder->andWhere(' e.slug = :slug ')->setParameter('slug', $opts[self::CRITERIA_SLUG]);
        }
        if (!empty($opts[self::CRITERIA_START])) {
            $startQuery = ' (e.startDateTime IS NULL) OR (:start < e.startDateTime) ';
            $queryBuilder->andWhere($startQuery)->setParameter('start', $opts[self::CRITERIA_START]);
        }
        if (!empty($opts[self::CRITERIA_END])) {
            $endQuery = ' (e.endDateTime IS NULL) OR (:end < e.endDateTime) ';
            $queryBuilder->andWhere($endQuery)->setParameter('end', $opts[self::CRITERIA_END]);
        }
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('e.deleted IS NULL');
        }
        if (!empty($opts[self::CRITERIA_ONLY_ROOT])) {
            $queryBuilder->andWhere('e.superEvent IS NULL');
        }
        if (!empty($opts[self::CRITERIA_ONLY_WITHOUT_DATE])) {
            $queryBuilder->andWhere('e.startDateTime IS NULL');
            $queryBuilder->andWhere('e.endDateTime IS NULL');
        }
        if (!empty($opts[self::CRITERIA_EVENT_SERIES]) && $opts[self::CRITERIA_END] instanceof EventSeries) {
            $queryBuilder->andWhere('e.eventSeries = :event_series_id');
            $queryBuilder->setParameter('event_series_id', $opts[self::CRITERIA_EVENT_SERIES]->getId());
        }
        if (!empty($opts[self::CRITERIA_LOCATION]) && $opts[self::CRITERIA_END] instanceof Place) {
            $queryBuilder->andWhere('e.location = :location_id')->setParameter('location_id', $opts[self::CRITERIA_LOCATION]->getId());
        }
        if (!empty($opts[self::CRITERIA_ONLY_PUBLIC_ON_WEB])) {
            $queryBuilder->andWhere('e.public_on_web = true');
        }
        if (!empty($opts[self::CRITERIA_ONLY_PUBLIC_ON_WEB_ROUTE])) {
            $queryBuilder->andWhere('e.public_on_web_route = true');
        }
        if (!empty($opts[self::CRITERIA_EVENT_TYPE_OF_TYPE]) && is_string($opts[self::CRITERIA_EVENT_TYPE_OF_TYPE])) {
            $queryBuilder->leftJoin('e.eventType', 'eventType');
            $queryBuilder->andWhere('eventType.type = :type_type');
            $queryBuilder->setParameter('type_type', $opts[self::CRITERIA_EVENT_TYPE_OF_TYPE]);
        }
        if (!empty($opts[self::CRITERIA_EVENT_TYPE]) && $opts[self::CRITERIA_EVENT_TYPE] instanceof EventType) {
            $queryBuilder->andWhere('e.eventType = :event_type_id');
            $queryBuilder->setParameter('event_type_id', $opts[self::CRITERIA_EVENT_TYPE]->getId());
        }
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }
        if (null !== $offset) {
            $queryBuilder->setFirstResult($offset);
        }

        return $queryBuilder->orderBy('name', 'ASC')->addOrderBy('id', 'ASC');
    }

    public function getEvents(?array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        return new ArrayCollection($this->getEventsQueryBuilder($opts, $limit, $offset)->getQuery()->getArrayResult());
    }
}
