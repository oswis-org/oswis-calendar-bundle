<?php

namespace OswisOrg\OswisCalendarBundle\Service\Registration;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use Psr\Log\LoggerInterface;

class RegistrationFlagService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
    ) {
    }

    final public function create(RegistrationFlag $participantFlag): ?RegistrationFlag
    {
        try {
            $this->em->persist($participantFlag);
            $this->em->flush();
            $infoMessage = 'CREATE: Created flag (by service): '
                           .$participantFlag->getId()
                           .' '
                           .$participantFlag->getName()
                           .'.';
            $this->logger->info($infoMessage);

            return $participantFlag;
        } catch (Exception $e) {
            $this->logger->info('ERROR: RegistrationFlag not created (by service): '.$e->getMessage());

            return null;
        }
    }
}
