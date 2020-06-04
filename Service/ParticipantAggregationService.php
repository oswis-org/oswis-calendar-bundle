<?php

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;

class ParticipantAggregationService
{
    protected EntityManagerInterface $em;

    protected ParticipantService $participantService;

    public function __construct(EntityManagerInterface $em, ParticipantService $participantService)
    {
        $this->em = $em;
        $this->participantService = $participantService;
    }

//    public function getAllowedFlagsAggregatedByType(?ParticipantType $eventParticipantType = null, bool $onlyPublic = false): array
//    {
//        $flags = [];
//        foreach ($this->getParticipantFlagRanges($eventParticipantType, null, $onlyPublic) as $flagInEvent) {
//            if ($flagInEvent instanceof ParticipantFlagRange && $flag = $flagInEvent->getFlag()) {
//                $flagTypeSlug = $flag->getFlagType() ? $flag->getFlagType()->getSlug() : '0';
//                $flags[$flagTypeSlug]['flagType'] = $flag->getFlagType();
//                $flags[$flagTypeSlug]['flags'][] = $flag;
//            }
//        }
//
//        return $flags;
//    }
}
