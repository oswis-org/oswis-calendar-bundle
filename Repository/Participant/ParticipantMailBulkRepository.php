<?php

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;

/** @extends ServiceEntityRepository<ParticipantMailBulk> */
class ParticipantMailBulkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantMailBulk::class);
    }

    /**
     * Bulks that still need draining (queued or mid-flight) and are DUE (čas „odeslat po" uplynul), oldest first.
     * Zrušené a naplánované do budoucna se nevrací (dávka 3.2).
     *
     * @return list<ParticipantMailBulk>
     */
    public function findPending(int $limit = 20, ?\DateTimeImmutable $now = null): array
    {
        $rows = $this->createQueryBuilder('b')
            ->where('b.status NOT IN (:finished)')
            ->andWhere('b.sendAfter IS NULL OR b.sendAfter <= :now')
            ->setParameter('finished', [ParticipantMailBulk::STATUS_DONE, ParticipantMailBulk::STATUS_CANCELLED])
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->orderBy('b.createdAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_values(
            array_filter(
                is_array($rows) ? $rows : [],
                static fn (mixed $row): bool => $row instanceof ParticipantMailBulk,
            ),
        );
    }

    /**
     * Most recent bulks (any status), newest first — for the status page.
     *
     * @return list<ParticipantMailBulk>
     */
    public function findRecent(int $limit = 30): array
    {
        $rows = $this->createQueryBuilder('b')
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_values(
            array_filter(
                is_array($rows) ? $rows : [],
                static fn (mixed $row): bool => $row instanceof ParticipantMailBulk,
            ),
        );
    }

    final public function findOneBy(array $criteria, ?array $orderBy = null): ?ParticipantMailBulk
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantMailBulk ? $result : null;
    }
}
