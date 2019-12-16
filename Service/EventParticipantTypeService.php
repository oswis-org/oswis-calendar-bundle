<?php

namespace Zakjakub\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventParticipantTypeService
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(?Nameable $nameable = null, ?string $type = null): ?EventParticipantType
    {
        try {
            $entity = new EventParticipantType($nameable, $type);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event participant type (by manager): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event participant type not created (by manager): '.$e->getMessage()) : null;

            return null;
        }
    }
}
