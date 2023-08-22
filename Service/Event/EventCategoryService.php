<?php

namespace OswisOrg\OswisCalendarBundle\Service\Event;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Psr\Log\LoggerInterface;

class EventCategoryService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
    )
    {
    }

    final public function create(
        ?Nameable $nameable = null,
        ?string $type = null,
        ?string $color = null
    ): ?EventCategory
    {
        try {
            $entity = new EventCategory($nameable, $type, $color);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event type (by service): ' . $entity->getId() . ' ' . $entity->getName() . '.';
            $this->logger->info($infoMessage);

            return $entity;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Event event type not created (by service): ' . $e->getMessage());

            return null;
        }
    }

    final public function updateActiveRevisions(): void
    {
        //        foreach ($this->em->getRepository(EventType::class)->findAll() as $eventType) {
        //            assert($eventType instanceof EventType);
        //            $eventType->destroyRevisions();
        //            $this->em->persist($eventType);
        //        }
        //        $this->em->flush();
    }
}
