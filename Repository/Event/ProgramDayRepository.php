<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Event;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\ProgramDay;

/**
 * @extends ServiceEntityRepository<ProgramDay>
 */
class ProgramDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramDay::class);
    }

    /**
     * Dny programu daného turnusu, chronologicky.
     *
     * @return list<ProgramDay>
     */
    public function getByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('d')
            ->where('d.event = :event')
            ->setParameter('event', $event)
            ->addOrderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof ProgramDay,
        )) : [];
    }
}
