<?php

namespace OswisOrg\OswisCalendarBundle\Repository\Registration;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;

class RegistrationFlagRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistrationFlag::class);
    }

    final public function findOneBy(array $criteria, ?array $orderBy = null): ?RegistrationFlag
    {
        $flag = parent::findOneBy($criteria, $orderBy);

        return $flag instanceof RegistrationFlag ? $flag : null;
    }
}
