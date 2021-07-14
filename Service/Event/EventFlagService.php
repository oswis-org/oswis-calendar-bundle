<?php

namespace OswisOrg\OswisCalendarBundle\Service\Event;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventFlag;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Psr\Log\LoggerInterface;

class EventFlagService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
    ) {
    }

    final public function create(?Nameable $nameable = null): ?EventFlag
    {
        try {
            $entity = new EventFlag($nameable);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event flag (by service): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger->info($infoMessage);

            return $entity;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Event event flag not created (by service): '.$e->getMessage());

            return null;
        }
    }
}
