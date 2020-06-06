<?php

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use Psr\Log\LoggerInterface;

class ParticipantFlagService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(ParticipantFlag $participantFlag): ?ParticipantFlag
    {
        try {
            $this->em->persist($participantFlag);
            $this->em->flush();
            $infoMessage = 'CREATE: Created participant flag (by service): '.$participantFlag->getId().' '.$participantFlag->getName().'.';
            $this->logger->info($infoMessage);

            return $participantFlag;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Event participant flag not created (by service): '.$e->getMessage());

            return null;
        }
    }
}
