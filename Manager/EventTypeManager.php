<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventType;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventTypeManager
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(?Nameable $nameable = null, ?string $type = null, ?string $color = null): ?EventType
    {
        try {
            $entity = new EventType($nameable, $type, $color);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event type (by manager): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event type not created (by manager): '.$e->getMessage()) : null;

            return null;
        }
    }

    /**
     * @noinspection PhpUnused
     */
    final public function updateActiveRevisions(): void
    {
        foreach ($this->em->getRepository(EventType::class)->findAll() as $eventType) {
            assert($eventType instanceof EventType);
            $eventType->destroyRevisions();
            $this->em->persist($eventType);
        }
        $this->em->flush();
    }
}
