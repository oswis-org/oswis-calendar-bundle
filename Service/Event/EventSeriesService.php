<?php

namespace OswisOrg\OswisCalendarBundle\Service\Event;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventGroup;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Psr\Log\LoggerInterface;

class EventSeriesService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
    )
    {
    }

    final public function create(?Nameable $nameable = null): ?EventGroup
    {
        try {
            $entity = new EventGroup($nameable);
            $this->em->persist($entity);
            $this->em->flush();
            $this->logger->info(
                'CREATE: Created event series (by service): ' . $entity->getId() . ' ' . $entity->getName() . '.'
            );

            return $entity;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Event event series not created (by service): ' . $e->getMessage());

            return null;
        }
    }
}
