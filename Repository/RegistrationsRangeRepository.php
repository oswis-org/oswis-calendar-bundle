<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;

class RegistrationsRangeRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_SLUG = 'slug';
    public const CRITERIA_EVENT = 'event';
    public const CRITERIA_PARTICIPANT_TYPE_STRING = 'participantTypeString';
    public const CRITERIA_PARTICIPANT_TYPE = 'participantType';
    public const CRITERIA_PUBLIC_ON_WEB = 'publicOnWeb';
    public const CRITERIA_ONLY_ACTIVE = 'onlyActive';

    public function findOneBy(array $criteria, ?array $orderBy = null): ?RegistrationsRange
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof RegistrationsRange ? $result : null;
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
        $this->addParticipantTypeQuery($queryBuilder, $opts);
        $this->addParticipantTypeStringQuery($queryBuilder, $opts);
        $this->addOnlyActiveQuery($queryBuilder, $opts);
        $this->addPublicOnWebQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder);

        return $queryBuilder;
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

    private function addParticipantTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE]) && $opts[self::CRITERIA_PARTICIPANT_TYPE] instanceof ParticipantType) {
            $queryBuilder->andWhere('range.participantType = :type_id');
            $queryBuilder->setParameter('type_id', $opts[self::CRITERIA_PARTICIPANT_TYPE]->getId());
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

    private function addParticipantTypeStringQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE_STRING]) && is_string($opts[self::CRITERIA_PARTICIPANT_TYPE_STRING])) {
            $queryBuilder->leftJoin('range.participantType', 'type');
            $queryBuilder->andWhere('type.type = :type_string');
            $queryBuilder->setParameter('type_string', $opts[self::CRITERIA_PARTICIPANT_TYPE_STRING]);
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

    public function getRegistrationsRange(?array $opts = []): ?RegistrationsRange
    {
        try {
            $registrationsRange = $this->getRegistrationsRangesQueryBuilder($opts)->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $registrationsRange instanceof RegistrationsRange ? $registrationsRange : null;
    }
}



