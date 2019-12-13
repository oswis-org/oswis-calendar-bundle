<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventFlagManager
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(?Nameable $nameable = null): ?EventFlag
    {
        try {
            $entity = new EventFlag($nameable);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event flag (by manager): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event flag not created (by manager): '.$e->getMessage()) : null;

            return null;
        }
    }
}
