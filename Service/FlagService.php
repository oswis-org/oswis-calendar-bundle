<?php

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\Flag;
use Psr\Log\LoggerInterface;

class FlagService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(Flag $participantFlag): ?Flag
    {
        try {
            $this->em->persist($participantFlag);
            $this->em->flush();
            $infoMessage = 'CREATE: Created flag (by service): '.$participantFlag->getId().' '.$participantFlag->getName().'.';
            $this->logger->info($infoMessage);

            return $participantFlag;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Flag not created (by service): '.$e->getMessage());

            return null;
        }
    }
}
