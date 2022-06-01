<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;

class ParticipantFlagRepository extends EntityRepository
{
    public const CRITERIA_ID              = 'id';
    public const CRITERIA_FLAG_RANGE      = 'flagOffer';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';

    public function findOneBy(array $criteria, ?array $orderBy = null): ?ParticipantFlag
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantFlag ? $result : null;
    }

    public function countParticipantFlagGroups(array $opts = [], ?int $limit = null, ?int $offset = null): ?int
    {
        $queryBuilder = $this->getQueryBuilder($opts, $limit, $offset)->select(' COUNT(item.id) ');
        try {
            $result = $queryBuilder->getQuery()->getSingleScalarResult();

            return is_string($result) || is_numeric($result) ? (int)$result : null;
        } catch (NoResultException|NonUniqueResultException) {
            return null;
        }
    }

    public function getQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('item');
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
            $queryBuilder->andWhere(' item.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function addFlagRangeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_FLAG_RANGE]) && $opts[self::CRITERIA_FLAG_RANGE] instanceof RegistrationFlagOffer) {
            $queryBuilder->andWhere('item.flagOffer = :flag_range_id');
            $queryBuilder->setParameter('flag_range_id', $opts[self::CRITERIA_FLAG_RANGE]->getId());
        }
    }

    private function addIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('item.deletedAt IS NULL');
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
        $queryBuilder->addOrderBy('item.id', 'ASC');
    }

    public function getParticipantFlagGroups(array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        $queryBuilder = $this->getQueryBuilder($opts, $limit, $offset);
        $result       = $queryBuilder->getQuery()->getResult();

        return new ArrayCollection(is_array($result) ? $result : []);
    }

    public function getParticipantFlag(?array $opts = []): ?ParticipantFlag
    {
        try {
            $participantFlag = $this->getQueryBuilder($opts ?? [])->getQuery()->getOneOrNullResult();
        } catch (Exception) {
            return null;
        }

        return $participantFlag instanceof ParticipantFlag ? $participantFlag : null;
    }
}



