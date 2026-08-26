<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Accommodation;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationUnit;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Bed;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Reservation;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * Počet AKTIVNÍCH rezervací v jednotce — reálný SQL COUNT (kontrola kapacity, žádné load-all).
     * Nezapočítává zrušené/no-show ani smazané účastníky.
     */
    /**
     * Obsazenost pokoje — ROZHODOVACÍ dotaz (podle něj se u příjezdového stolu přiděluje lůžko),
     * proto ZÁMĚRNĚ mimo druhoúrovňovou cache.
     *
     * ⚠️ `Reservation` má `#[Cache(NONSTRICT_READ_WRITE)]`; zastaralý počet by znamenal DVA LIDI
     * NA JEDNOM LŮŽKU — a u stolu se to pozná až na místě. Stejná třída chyby jako incident
     * s duplicitními platbami 16. 8. 2026
     * ({@see docs/OSWIS_1_INCIDENT_PAYMENT_DUPLICATES_2026-08-16.md}).
     */
    public function countActiveByUnit(AccommodationUnit $unit): int
    {
        $result = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->leftJoin('r.participant', 'p')
            ->where('r.unit = :unit')
            ->andWhere('r.status NOT IN (:inactive)')
            ->andWhere('p.id IS NULL OR p.deletedAt IS NULL')
            ->setParameter('unit', $unit)
            ->setParameter('inactive', [Reservation::STATUS_CANCELLED, Reservation::STATUS_NO_SHOW])
            ->getQuery()
            ->setCacheable(false)
            ->getSingleScalarResult();

        return is_numeric($result) ? (int) $result : 0;
    }

    /** Aktivní rezervace účastníka (kde bydlí) — 1 účastník = max 1 aktivní. */
    public function findActiveByParticipant(Participant $participant): ?Reservation
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.participant = :participant')
            ->andWhere('r.status NOT IN (:inactive)')
            ->setParameter('participant', $participant)
            ->setParameter('inactive', [Reservation::STATUS_CANCELLED, Reservation::STATUS_NO_SHOW])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Reservation ? $result : null;
    }

    /**
     * Rezervace v jednotce (kdo tam bydlí) — obousměrné dohledání.
     *
     * @return list<Reservation>
     */
    public function getByUnit(AccommodationUnit $unit): array
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.unit = :unit')
            ->andWhere('r.status NOT IN (:inactive)')
            ->setParameter('unit', $unit)
            ->setParameter('inactive', [Reservation::STATUS_CANCELLED, Reservation::STATUS_NO_SHOW])
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof Reservation,
        )) : [];
    }

    /**
     * Všechny aktivní rezervace turnusu (přes participant.event).
     *
     * @return list<Reservation>
     */
    public function getByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('r')
            ->innerJoin('r.participant', 'p')
            ->where('p.event = :event')
            ->andWhere('r.status NOT IN (:inactive)')
            ->setParameter('event', $event)
            ->setParameter('inactive', [Reservation::STATUS_CANCELLED, Reservation::STATUS_NO_SHOW])
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof Reservation,
        )) : [];
    }
    /**
     * Aktivní rezervace držící dané lůžko — volitelně bez rezervace jednoho účastníka.
     *
     * Vylučovat vlastní rezervaci je nutné kvůli přeřazení: obsluha často jen upřesní lůžko
     * v jednotce, kterou už člověk má, a bez téhle výjimky by si to hlásil jako obsazené sám sobě.
     */
    public function findActiveByBed(Bed $bed, ?Participant $except = null): ?Reservation
    {
        $builder = $this->createQueryBuilder('r')
            ->where('r.bed = :bed')
            ->andWhere('r.status NOT IN (:inactive)')
            ->setParameter('bed', $bed)
            ->setParameter('inactive', [Reservation::STATUS_CANCELLED, Reservation::STATUS_NO_SHOW])
            ->setMaxResults(1);
        if (null !== $except) {
            $builder->andWhere('r.participant != :except')->setParameter('except', $except);
        }
        $result = $builder->getQuery()->setCacheable(false)->getOneOrNullResult();

        return $result instanceof Reservation ? $result : null;
    }
}
