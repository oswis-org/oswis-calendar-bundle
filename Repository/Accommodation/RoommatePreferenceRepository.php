<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Accommodation;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\RoommatePreference;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;

/**
 * @extends ServiceEntityRepository<RoommatePreference>
 */
class RoommatePreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoommatePreference::class);
    }

    /** @return list<RoommatePreference> */
    public function findByParticipant(Participant $participant): array
    {
        return $this->findBy(['participant' => $participant]);
    }
}
