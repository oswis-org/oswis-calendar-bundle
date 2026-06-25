<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantGroup;

/**
 * @extends ServiceEntityRepository<ParticipantGroup>
 */
class ParticipantGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantGroup::class);
    }

    /**
     * Skupiny daného turnusu, seřazené dle pořadí na jídlo (dietáři první), pak dle názvu.
     *
     * @return list<ParticipantGroup>
     */
    public function getByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('g')
            ->where('g.event = :event')
            ->setParameter('event', $event)
            ->addOrderBy('g.mealOrder', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof ParticipantGroup,
        )) : [];
    }
}
