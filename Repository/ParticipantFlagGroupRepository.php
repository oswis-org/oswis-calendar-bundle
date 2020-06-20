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
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use OswisOrg\OswisCalendarBundle\Entity\Participant\RegistrationsFlagRange;

class ParticipantFlagGroupRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_FLAG_RANGE = 'flagRange';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';

    public function findOneBy(array $criteria, ?array $orderBy = null): ?ParticipantFlagGroup
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantFlagGroup ? $result : null;
    }

    public function getFlagRangesConnections(array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        $queryBuilder = $this->getFlagRangesConnectionsQueryBuilder($opts, $limit, $offset);

        return new ArrayCollection($queryBuilder->getQuery()->getResult());
    }

    public function getFlagRangesConnectionsQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('range');
        $this->addIdQuery($queryBuilder, $opts);
        $this->addFlagRangeQuery($queryBuilder, $opts);
        $this->addIncludeDeletedQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder);

        return $queryBuilder;
    }

    private function addIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' range_conn.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function addFlagRangeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_FLAG_RANGE]) && $opts[self::CRITERIA_FLAG_RANGE] instanceof RegistrationsFlagRange) {
            $queryBuilder->andWhere('range_conn.flagRange = :flag_range_id');
            $queryBuilder->setParameter('flag_range_id', $opts[self::CRITERIA_FLAG_RANGE]->getId());
        }
    }

    private function addIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('participant.deleted IS NULL');
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
        $queryBuilder->addOrderBy('range_conn.id', 'ASC');
    }

    public function getFlagRangeConnection(?array $opts = []): ?ParticipantFlagGroup
    {
        try {
            $flagRangeConnection = $this->getFlagRangesConnectionsQueryBuilder($opts)->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $flagRangeConnection instanceof ParticipantFlagGroup ? $flagRangeConnection : null;
    }
}



