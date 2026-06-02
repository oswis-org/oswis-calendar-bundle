<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;

/** @extends ServiceEntityRepository<ParticipantPayment> */
class ParticipantPaymentRepository extends ServiceEntityRepository
{
    public const string FILTER_ALL        = 'all';
    public const string FILTER_ORPHANED   = 'orphaned';
    public const string FILTER_WITH_ERROR = 'with-error';
    public const string FILTER_ASSIGNED   = 'assigned';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantPayment::class);
    }

    /**
     * @return list<ParticipantPayment>
     */
    public function findFiltered(string $filter, int $limit = 500): array
    {
        // Participant has fetch=EAGER on offer/event/participantCategory and AbstractContact
        // has fetch=EAGER on appUser — without join-fetching them here, hydrating each row's
        // participant fires 4 extra queries per distinct participant (the payments overview
        // measured ~1646 queries for 500 rows). These are all to-one joins, so they collapse
        // into the single result query with no row multiplication. EAGER stays intentional;
        // we just load it up-front instead of lazily (per the page-scoped optimisation rule).
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.participant', 'participant')->addSelect('participant')
            ->leftJoin('participant.contact', 'contact')->addSelect('contact')
            ->leftJoin('contact.appUser', 'appUser')->addSelect('appUser')
            ->leftJoin('participant.offer', 'offer')->addSelect('offer')
            ->leftJoin('participant.event', 'pEvent')->addSelect('pEvent')
            ->leftJoin('participant.participantCategory', 'pCategory')->addSelect('pCategory')
            ->leftJoin('p.import', 'import')->addSelect('import')
            ->orderBy('p.dateTime', 'DESC')
            ->setMaxResults($limit);

        $this->applyFilter($qb, $filter);

        /** @var list<ParticipantPayment> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function countFiltered(string $filter): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $this->applyFilter($qb, $filter);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function applyFilter(QueryBuilder $qb, string $filter): void
    {
        match ($filter) {
            self::FILTER_ORPHANED   => $qb->andWhere('p.participant IS NULL'),
            self::FILTER_WITH_ERROR => $qb->andWhere("p.errorMessage IS NOT NULL AND p.errorMessage <> ''"),
            self::FILTER_ASSIGNED   => $qb->andWhere('p.participant IS NOT NULL'),
            self::FILTER_ALL        => $qb,
            default                 => throw new InvalidArgumentException("Unknown payment filter '$filter'"),
        };
    }
}
