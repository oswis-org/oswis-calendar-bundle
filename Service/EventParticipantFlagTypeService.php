<?php

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use OswisOrg\OswisCoreBundle\Entity\Nameable;
use Psr\Log\LoggerInterface;

class EventParticipantFlagTypeService
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
        ?string $type = null,
        ?int $minFlagsAllowed = null,
        ?int $maxFlagsAllowed = null
    ): ?EventParticipantFlagType {
        try {
            $entity = new EventParticipantFlagType($nameable, $type, $minFlagsAllowed, $maxFlagsAllowed);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event participant flag type (by service): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event participant flag type not created (by service): '.$e->getMessage()) : null;

            return null;
        }
    }
}
