<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Staff;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffAssignment;

/**
 * @extends ServiceEntityRepository<StaffAssignment>
 */
class StaffAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffAssignment::class);
    }

    /**
     * Všechny nesmazané závazky turnusu (služby + role u aktivit). Levné díky scope `turnus`.
     *
     * @return list<StaffAssignment>
     */
    public function getByTurnus(Event $turnus): array
    {
        return $this->filtered(
            $this->createQueryBuilder('a')->where('a.turnus = :turnus')->setParameter('turnus', $turnus)
        );
    }

    /**
     * Celodenní SLUŽBY turnusu (rozpis služeb) = závazky bez konkrétní aktivity.
     *
     * @return list<StaffAssignment>
     */
    public function getServicesByTurnus(Event $turnus): array
    {
        return $this->filtered(
            $this->createQueryBuilder('a')
                ->where('a.turnus = :turnus')->andWhere('a.activity IS NULL')
                ->setParameter('turnus', $turnus)
        );
    }

    /**
     * Závazky navázané na konkrétní aktivitu (vede/technika/svolávání u ní).
     *
     * @return list<StaffAssignment>
     */
    public function getByActivity(Event $activity): array
    {
        return $this->filtered(
            $this->createQueryBuilder('a')->where('a.activity = :activity')->setParameter('activity', $activity)
        );
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $qb
     *
     * @return list<StaffAssignment>
     */
    private function filtered(\Doctrine\ORM\QueryBuilder $qb): array
    {
        $result = $qb->andWhere('a.deletedAt IS NULL')->addOrderBy('a.id', 'ASC')->getQuery()->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof StaffAssignment,
        )) : [];
    }
}
