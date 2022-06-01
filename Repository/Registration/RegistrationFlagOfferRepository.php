<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Repository\Registration;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use LogicException;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;

class RegistrationFlagOfferRepository extends ServiceEntityRepository
{
    public const CRITERIA_ID                 = 'id';
    public const CRITERIA_SLUG               = 'slug';
    public const CRITERIA_TYPE               = 'participantType';
    public const CRITERIA_EVENT              = 'event';
    public const CRITERIA_TYPE_OF_TYPE       = 'participantTypeOfType';
    public const CRITERIA_ONLY_PUBLIC_ON_WEB = 'onlyPublicOnWeb';
    public const CRITERIA_INCLUDE_DELETED    = 'includeDeleted';

    /**
     * @param  ManagerRegistry  $registry
     *
     * @throws LogicException
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistrationFlagOffer::class);
    }

    public function findOneBy(array $criteria, array $orderBy = null): ?RegistrationFlagOffer
    {
        $flagRange = parent::findOneBy($criteria, $orderBy);

        return $flagRange instanceof RegistrationFlagOffer ? $flagRange : null;
    }

    public function getFlagRange(?array $opts = []): ?RegistrationFlagOffer
    {
        try {
            $flagRange = $this->getQueryBuilder($opts ?? [])->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $flagRange instanceof RegistrationFlagOffer ? $flagRange : null;
    }

    public function getQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('flagRange');
        $this->addIdQuery($queryBuilder, $opts);
        $this->addSlugQuery($queryBuilder, $opts);
        $this->addOnlyPublicOnWebQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder);

        return $queryBuilder;
    }

    private function addIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' flagRange.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function addSlugQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_SLUG])) {
            $queryBuilder->andWhere(' flagRange.slug = :slug ')->setParameter('slug', $opts[self::CRITERIA_SLUG]);
        }
    }

    private function addOnlyPublicOnWebQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ONLY_PUBLIC_ON_WEB])) {
            $queryBuilder->andWhere('flagRange.publicOnWeb = true');
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
        $queryBuilder->addOrderBy('flagRange.name', 'ASC');
        $queryBuilder->addOrderBy('flagRange.id', 'ASC');
    }

    public function getFlagRanges(?array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        $result = $this->getQueryBuilder($opts ?? [], $limit, $offset)->getQuery()->getResult();

        return new ArrayCollection(is_array($result) ? $result : []);
    }
}
