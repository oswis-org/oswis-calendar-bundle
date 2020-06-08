<?php

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventGroup;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Psr\Log\LoggerInterface;

class EventSeriesService
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(?Nameable $nameable = null): ?EventGroup
    {
        try {
            $entity = new EventGroup($nameable);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event series (by service): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event series not created (by service): '.$e->getMessage()) : null;

            return null;
        }
    }
}
