<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\CheckIn;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\CheckInStation;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;

/**
 * @extends ServiceEntityRepository<CheckInStation>
 */
class CheckInStationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckInStation::class);
    }

    /**
     * Stanice daného turnusu v pořadí pipeline (orderNumber ASC, pak název), bez smazaných.
     *
     * @return list<CheckInStation>
     */
    public function getByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('s')
            ->where('s.event = :event')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('event', $event)
            ->addOrderBy('s.orderNumber', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof CheckInStation,
        )) : [];
    }
}
