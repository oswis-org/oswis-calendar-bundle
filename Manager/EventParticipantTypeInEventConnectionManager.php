<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantTypeInEventConnection;

class EventParticipantTypeInEventConnectionManager
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(?EventParticipantType $eventParticipantType = null, ?Event $event = null): EventParticipantTypeInEventConnection
    {
        try {
            $entity = new EventParticipantTypeInEventConnection($eventParticipantType, $event);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event participant type in event connection (by manager): '.$entity->getId().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event participant type in event connection not created (by manager): '.$e->getMessage()) : null;

            return null;
        }
    }
}
