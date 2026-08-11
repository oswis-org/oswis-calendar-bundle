<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Meal;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Meal\ParticipantMealChoice;

/**
 * @extends ServiceEntityRepository<ParticipantMealChoice>
 */
final class ParticipantMealChoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantMealChoice::class);
    }
}
