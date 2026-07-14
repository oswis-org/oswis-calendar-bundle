<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Accommodation;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\RoommateGroup;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;

/**
 * @extends ServiceEntityRepository<RoommateGroup>
 */
class RoommateGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoommateGroup::class);
    }

    /** @return list<RoommateGroup> */
    public function getByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('g')
            ->where('g.event = :event')
            ->andWhere('g.deletedAt IS NULL')
            ->setParameter('event', $event)
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof RoommateGroup,
        )) : [];
    }

    /**
     * Skupiny, jichž je účastník členem (pro constraint „spolubydlící do jedné jednotky").
     *
     * @return list<RoommateGroup>
     */
    public function findByMember(Participant $participant): array
    {
        $result = $this->createQueryBuilder('g')
            ->innerJoin('g.members', 'm')
            ->where('m = :participant')
            ->andWhere('g.deletedAt IS NULL')
            ->setParameter('participant', $participant)
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof RoommateGroup,
        )) : [];
    }
}
