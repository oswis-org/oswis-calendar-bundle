<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Accommodation;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationFeature;

/**
 * @extends ServiceEntityRepository<AccommodationFeature>
 */
class AccommodationFeatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccommodationFeature::class);
    }

    public function findOneByCode(string $code): ?AccommodationFeature
    {
        $result = $this->findOneBy(['code' => $code]);

        return $result instanceof AccommodationFeature ? $result : null;
    }
}
