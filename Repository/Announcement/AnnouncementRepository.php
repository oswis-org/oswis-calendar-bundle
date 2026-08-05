<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Announcement;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Announcement\Announcement;

/**
 * @extends ServiceEntityRepository<Announcement>
 */
final class AnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Announcement::class);
    }
}
