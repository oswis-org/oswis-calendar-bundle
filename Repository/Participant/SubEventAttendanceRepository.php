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

    /**
     * Kolik lidí je na aktivitě — ROZHODOVACÍ dotaz (blokuje zápis při plné kapacitě),
     * proto ZÁMĚRNĚ mimo druhoúrovňovou cache.
     *
     * ⚠️ `SubEventAttendance` má `#[Cache(NONSTRICT_READ_WRITE)]`. Kdyby se počet četl z cache,
     * mohl by být zastaralý a aktivita by se přeplnila — přesně takhle (jen u plateb) vzniklo
     * 16. 8. 2026 102 fiktivních plateb, protože dedup četl přes zastaralou L2
     * ({@see docs/OSWIS_1_INCIDENT_PAYMENT_DUPLICATES_2026-08-16.md}).
     * Transakce proti tomu NEPOMŮŽE: cache se čte dřív, než se sáhne do databáze.
     */
    public function countActiveByEvent(Event $event): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.event = :event')
            ->andWhere('a.status = :status')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('event', $event)
            ->setParameter('status', SubEventAttendance::STATUS_REGISTERED);

        return (int) $qb->getQuery()->setCacheable(false)->getSingleScalarResult();
    }

    /**
     * @return list<SubEventAttendance>
     */
    public function getActiveByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.event = :event')
            ->andWhere('a.status = :status')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('event', $event)
            ->setParameter('status', SubEventAttendance::STATUS_REGISTERED)
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof SubEventAttendance,
        )) : [];
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

    /**
     * Je člověk na aktivitě už přihlášený? ROZHODOVACÍ dotaz (brání dvojímu přihlášení), proto
     * ZÁMĚRNĚ mimo druhoúrovňovou cache — zastaralá odpověď by vyrobila duplicitní obsazení
     * kapacity. Viz poznámka u {@see countActiveByEvent()}.
     */
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
            ->setCacheable(false)
            ->getOneOrNullResult();

        return $result instanceof SubEventAttendance ? $result : null;
    }
}
