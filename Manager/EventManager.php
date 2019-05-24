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
    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        EntityManagerInterface $em,
        ?LoggerInterface $logger = null
    ) {
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
        ?EventSeries $eventSeries = null
    ): Event {
        try {
            $em = $this->em;
            $entity = new Event(
                $nameable,
                $superEvent,
                $location,
                $eventType,
                $startDateTime,
                $endDateTime,
                $eventSeries
            );
            $em->persist($entity);
            $em->flush();
            $infoMessage = 'CREATE: Created event (by manager): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger
                ? $this->logger->info('ERROR: Event not created (by manager): '.$e->getMessage())
                : null;

            return null;
        }
    }
}
