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
     * Bulks that still need draining (queued or mid-flight), oldest first.
     *
     * @return list<ParticipantMailBulk>
     */
    public function findPending(int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('b')
            ->where('b.status != :done')
            ->setParameter('done', ParticipantMailBulk::STATUS_DONE)
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

    final public function findOneBy(array $criteria, ?array $orderBy = null): ?ParticipantMailBulk
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantMailBulk ? $result : null;
    }
}
