<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\SubEventAttendance;

/**
 * @extends ServiceEntityRepository<SubEventAttendance>
 */
class SubEventAttendanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubEventAttendance::class);
    }


    public const CRITERIA_PARTICIPANT = 'participant';
    public const CRITERIA_EVENT       = 'event';
    public const CRITERIA_STATUS      = 'status';

    public function countActiveByEvent(Event $event): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.event = :event')
            ->andWhere('a.status = :status')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('event', $event)
            ->setParameter('status', SubEventAttendance::STATUS_REGISTERED);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<SubEventAttendance>
     */
    public function getActiveByParticipant(Participant $participant): array
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.participant = :participant')
            ->andWhere('a.status = :status')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('participant', $participant)
            ->setParameter('status', SubEventAttendance::STATUS_REGISTERED)
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof SubEventAttendance,
        )) : [];
    }

    public function findActiveForParticipantAndEvent(Participant $participant, Event $event): ?SubEventAttendance
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.participant = :participant')
            ->andWhere('a.event = :event')
            ->andWhere('a.status = :status')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('participant', $participant)
            ->setParameter('event', $event)
            ->setParameter('status', SubEventAttendance::STATUS_REGISTERED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SubEventAttendance ? $result : null;
    }
}
