<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Accommodation;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationUnit;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Facility;

/**
 * @extends ServiceEntityRepository<AccommodationUnit>
 */
class AccommodationUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccommodationUnit::class);
    }

    /**
     * Jednotky zařízení (bez smazaných), řazené dle názvu.
     *
     * @return list<AccommodationUnit>
     */
    public function getByFacility(Facility $facility): array
    {
        $result = $this->createQueryBuilder('u')
            ->where('u.facility = :facility')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('facility', $facility)
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof AccommodationUnit,
        )) : [];
    }
}
