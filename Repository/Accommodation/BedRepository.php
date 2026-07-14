<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Accommodation;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Bed;

/**
 * @extends ServiceEntityRepository<Bed>
 */
class BedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bed::class);
    }
}
