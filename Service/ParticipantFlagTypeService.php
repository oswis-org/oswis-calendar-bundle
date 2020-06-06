<?php

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagType;
use Psr\Log\LoggerInterface;

class ParticipantFlagTypeService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(ParticipantFlagType $flagType): ?ParticipantFlagType
    {
        try {
            $this->em->persist($flagType);
            $this->em->flush();
            $this->logger->info('CREATE: Created participant flag type (by service): '.$flagType->getId().' '.$flagType->getShortName().'.');

            return $flagType;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Event participant flag type not created (by service): '.$e->getMessage());

            return null;
        }
    }
}
