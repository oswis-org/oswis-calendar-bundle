<?php

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use Psr\Log\LoggerInterface;

class EventParticipantFlagService
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(
        ?Nameable $nameable = null,
        ?EventParticipantFlagType $participantFlagType = null,
        ?Publicity $publicity = null
    ): ?EventParticipantFlag {
        try {
            $entity = new EventParticipantFlag($nameable, $participantFlagType, $publicity);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event participant flag (by service): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event participant flag not created (by service): '.$e->getMessage()) : null;

            return null;
        }
    }
}
