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
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRegistration;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;

class ParticipantRegistrationRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_RANGE = 'offer';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';

    public function findOneBy(array $criteria, ?array $orderBy = null): ?ParticipantRegistration
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantRegistration ? $result : null;
    }

    public function countRangesConnections(array $opts = []): ?int
    {
        $queryBuilder = $this->getRangesConnectionsQueryBuilder($opts)->select(' COUNT(participant_range.id) ');
        try {
            $result = $queryBuilder->getQuery()->getSingleScalarResult();

            return (is_string($result) || is_numeric($result)) ? (int)$result : null;
        } catch (NoResultException|NonUniqueResultException) {
            return null;
        }
    }

    public function getRangesConnectionsQueryBuilder(
        array $opts = [],
        ?int $limit = null,
        ?int $offset = null,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('participant_range');
        $this->addIdQuery($queryBuilder, $opts);
        $this->addRangeQuery($queryBuilder, $opts);
        $this->addIncludeDeletedQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder);

        return $queryBuilder;
    }

    private function addIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' participant_range.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function addRangeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_RANGE]) && $opts[self::CRITERIA_RANGE] instanceof RegistrationOffer) {
            $queryBuilder->andWhere('participant_range.offer = :range_id');
            $queryBuilder->setParameter('range_id', $opts[self::CRITERIA_RANGE]->getId());
        }
    }

    /**
     * Vyřadí zrušené registrace — a s nimi i registrace zrušených PŘIHLÁŠEK.
     *
     * ⚠️ Druhá půlka je snadné opomenutí s drahým následkem: přes tenhle dotaz počítá
     * {@see RegistrationOfferService::updateUsage()} obsazenost turnusu. Odhlášení účastníka je
     * soft-delete přihlášky, který její řádek v `calendar_participant_range` nechává být
     * (`deleted_at` zůstane prázdné) — bez druhé podmínky by tedy odhlášený člověk držel místo
     * dál a turnus by se nikdy neuvolnil. Naměřeno 23. 8. 2026: 11 takových registrací
     * v ročnících 2022 a 2024 (turnus 76: čítač 208 proti skutečným 200).
     *
     * Tatáž chyba byla v {@see ParticipantFlagRepository}, kde nafukovala čítače příznaků.
     * Gedmo filtr `softdeleteable` v této aplikaci zapnutý není, takže filtrovat je nutné ručně.
     *
     * `leftJoin` (ne `innerJoin`) záměrně: vazba na přihlášku je nullable a osiřelé registrace
     * se počítaly odjakživa — tohle jim chování nemění.
     */
    private function addIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('participant_range.deletedAt IS NULL');
            $queryBuilder->leftJoin('participant_range.participant', 'participantForDeleted');
            $queryBuilder->andWhere('participantForDeleted.deletedAt IS NULL');
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
        $queryBuilder->addOrderBy('participant_range.id', 'ASC');
    }

    public function getRangesConnections(array $opts = [], ?int $limit = null, ?int $offset = null): Collection
    {
        $queryBuilder = $this->getRangesConnectionsQueryBuilder($opts, $limit, $offset);
        $result = $queryBuilder->getQuery()->getResult();

        return new ArrayCollection(is_array($result) ? $result : []);
    }

    public function getFlagRangeConnection(?array $opts = []): ?ParticipantRegistration
    {
        try {
            $rangeConnection = $this->getRangesConnectionsQueryBuilder($opts ?? [])->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $rangeConnection instanceof ParticipantRegistration ? $rangeConnection : null;
    }
}



