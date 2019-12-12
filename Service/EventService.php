<?php

namespace Zakjakub\OswisCalendarBundle\Service;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventType;
use Zakjakub\OswisCalendarBundle\Repository\EventRepository;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    protected EventRepository $eventRepo;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
        $this->eventRepo = $this->em->getRepository(Event::class);
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
        $entity = new Event($nameable, $superEvent, $location, $eventType, $startDateTime, $endDateTime, $eventSeries, $priceRecursiveFromParent);
        $this->em->persist($entity);
        $this->em->flush();
        $this->logger->info('CREATE: Created event (by manager): '.$entity->getId().' '.$entity->getName().'.');

        return $entity;
    }


}