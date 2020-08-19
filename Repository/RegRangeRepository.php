<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegRange;

class RegRangeRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_SLUG = 'slug';
    public const CRITERIA_EVENT = 'event';
    public const CRITERIA_PARTICIPANT_TYPE = 'participantType';
    public const CRITERIA_PARTICIPANT_CATEGORY = 'participantCategory';
    public const CRITERIA_PUBLIC_ON_WEB = 'publicOnWeb';
    public const CRITERIA_ONLY_ACTIVE = 'onlyActive';

    public function findOneBy(array $criteria, ?array $orderBy = null): ?RegRange
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof RegRange ? $result : null;
    }

    public function getRegistrationsRanges(array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        $queryBuilder = $this->getRegistrationsRangesQueryBuilder($opts, $limit, $offset);

        return new ArrayCollection($queryBuilder->getQuery()->getResult());
    }

    public function getRegistrationsRangesQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('range');
        $this->addIdQuery($queryBuilder, $opts);
        $this->addSlugQuery($queryBuilder, $opts);
        $this->addEventQuery($queryBuilder, $opts);
        $this->addParticipantCategoryQuery($queryBuilder, $opts);
        $this->addParticipantTypeQuery($queryBuilder, $opts);
        $this->addOnlyActiveQuery($queryBuilder, $opts);
        $this->addPublicOnWebQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder);

        return $queryBuilder;
    }

    public function getRegistrationsRange(?array $opts = []): ?RegRange
    {
        try {
            $registrationsRange = $this->getRegistrationsRangesQueryBuilder($opts)->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $registrationsRange instanceof RegRange ? $registrationsRange : null;
    }

    private function addIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' range.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function addSlugQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_SLUG])) {
            $queryBuilder->andWhere(' range.slug = :slug ')->setParameter('slug', $opts[self::CRITERIA_SLUG]);
        }
    }

    private function addEventQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_EVENT]) && $opts[self::CRITERIA_EVENT] instanceof Event) {
            $queryBuilder->andWhere('range.event = :event_id');
            $queryBuilder->setParameter('event_id', $opts[self::CRITERIA_EVENT]->getId());
        }
    }

    private function addParticipantCategoryQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_CATEGORY]) && $opts[self::CRITERIA_PARTICIPANT_CATEGORY] instanceof ParticipantCategory) {
            $queryBuilder->andWhere('range.participantCategory = :type_id');
            $queryBuilder->setParameter('type_id', $opts[self::CRITERIA_PARTICIPANT_CATEGORY]->getId());
        }
    }

    private function addParticipantTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE]) && is_string($opts[self::CRITERIA_PARTICIPANT_TYPE])) {
            $queryBuilder->leftJoin('range.participantCategory', 'type');
            $queryBuilder->andWhere('type.type = :type_string');
            $queryBuilder->setParameter('type_string', $opts[self::CRITERIA_PARTICIPANT_TYPE]);
        }
    }

    private function addOnlyActiveQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_ACTIVE]) && $opts[self::CRITERIA_ONLY_ACTIVE]) {
            $startQuery = ' (range.startDateTime IS NULL) OR (:now > range.startDateTime) ';
            $endQuery = ' (range.endDateTime IS NULL) OR (:now < range.endDateTime) ';
            $queryBuilder->andWhere($startQuery)->andWhere($endQuery)->setParameter('now', new DateTime());
        }
    }

    private function addPublicOnWebQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (null !== ($opts[self::CRITERIA_PUBLIC_ON_WEB] ?? null)) {
            $queryBuilder->andWhere('range.publicOnWeb = :publicOnWeb')->setParameter('publicOnWeb', (bool)$opts[self::CRITERIA_PUBLIC_ON_WEB]);
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

    private function addOrderBy(QueryBuilder $queryBuilder): void
    {
        $queryBuilder->addOrderBy('range.id', 'ASC');
    }
}



