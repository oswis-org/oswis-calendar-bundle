<?php

namespace Zakjakub\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventParticipantFlagService
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
        ?EventParticipantFlagType $eventParticipantFlagType = null,
        ?bool $publicInIS = null,
        ?bool $publicInPortal = null,
        ?bool $publicOnWeb = null,
        ?bool $publicOnWebRoute = null
    ): ?EventParticipantFlag {
        try {
            $entity = new EventParticipantFlag($nameable, $eventParticipantFlagType, $publicInIS, $publicInPortal, $publicOnWeb, $publicOnWebRoute);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event participant flag (by manager): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event participant flag not created (by manager): '.$e->getMessage()) : null;

            return null;
        }
    }
}
