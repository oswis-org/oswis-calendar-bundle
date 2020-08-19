<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;

class ParticipantCategoryRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_SLUG = 'slug';
    public const CRITERIA_TYPE = 'participantType';

    public function findOneBy(array $criteria, array $orderBy = null): ?ParticipantCategory
    {
        $participantType = parent::findOneBy($criteria, $orderBy);

        return $participantType instanceof ParticipantCategory ? $participantType : null;
    }

    public function getParticipantCategory(?array $opts = []): ?ParticipantCategory
    {
        try {
            $participantType = $this->getQueryBuilder($opts)->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $participantType instanceof ParticipantCategory ? $participantType : null;
    }

    public function getQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('ept');
        $this->addIdQuery($queryBuilder, $opts);
        $this->addSlugQuery($queryBuilder, $opts);
        $this->addTypeQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder, true);

        return $queryBuilder;
    }

    public function getParticipantTypes(?array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        return new ArrayCollection(
            $this->getQueryBuilder($opts, $limit, $offset)->getQuery()->getResult()
        );
    }

    private function addIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' ept.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function addSlugQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_SLUG])) {
            $queryBuilder->andWhere(' ept.slug = :slug ')->setParameter('slug', $opts[self::CRITERIA_SLUG]);
        }
    }

    private function addTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_TYPE]) && is_string($opts[self::CRITERIA_TYPE])) {
            $queryBuilder->andWhere('ept.type = :type_string');
            $queryBuilder->setParameter('type_string', $opts[self::CRITERIA_TYPE]);
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

    private function addOrderBy(QueryBuilder $queryBuilder, bool $name = true): void
    {
        if ($name) {
            $queryBuilder->addOrderBy('ept.name', 'ASC');
        }
        $queryBuilder->addOrderBy('ept.id', 'ASC');
    }
}
