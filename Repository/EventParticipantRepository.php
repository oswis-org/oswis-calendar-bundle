<?php

namespace Zakjakub\OswisCalendarBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;

class EventParticipantRepository extends EntityRepository
{
    final public function findOneBy(array $criteria, array $orderBy = null): ?EventParticipant
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof EventParticipant ? $result : null;
    }
}
