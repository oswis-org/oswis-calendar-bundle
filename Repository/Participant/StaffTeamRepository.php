<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;

/**
 * @extends ServiceEntityRepository<StaffTeam>
 */
class StaffTeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffTeam::class);
    }

    /**
     * Podtýmy daného turnusu, seřazené dle názvu.
     *
     * @return list<StaffTeam>
     */
    public function getByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.event = :event')
            ->setParameter('event', $event)
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof StaffTeam,
        )) : [];
    }
}
