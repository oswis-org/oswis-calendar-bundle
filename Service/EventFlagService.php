<?php

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCoreBundle\Entity\Nameable;
use Psr\Log\LoggerInterface;

class EventFlagService
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
            $infoMessage = 'CREATE: Created event flag (by service): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event flag not created (by service): '.$e->getMessage()) : null;

            return null;
        }
    }
}
