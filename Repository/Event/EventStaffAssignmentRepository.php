<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Event;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventStaffAssignment;

/**
 * @extends ServiceEntityRepository<EventStaffAssignment>
 */
class EventStaffAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventStaffAssignment::class);
    }

    /**
     * Přiřazení vedoucích/služeb k dané aktivitě.
     *
     * @return list<EventStaffAssignment>
     */
    public function getByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.event = :event')
            ->setParameter('event', $event)
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof EventStaffAssignment,
        )) : [];
    }
}
