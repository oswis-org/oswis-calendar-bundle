<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventType;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventManager
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
        ?Event $superEvent = null,
        ?Place $location = null,
        ?EventType $eventType = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null,
        ?EventSeries $eventSeries = null,
        ?bool $priceRecursiveFromParent = null
    ): Event {
        try {
            $entity = new Event($nameable, $superEvent, $location, $eventType, $startDateTime, $endDateTime, $eventSeries, $priceRecursiveFromParent);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event (by manager): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event not created (by manager): '.$e->getMessage()) : null;

            return null;
        }
    }

    /**
     * @noinspection PhpUnused
     */
    final public function updateActiveRevisions(): void
    {
        foreach ($this->em->getRepository(Event::class)->findAll() as $event) {
            assert($event instanceof Event);
            $event->destroyRevisions();
            $this->em->persist($event);
        }
        $this->em->flush();
    }
}
