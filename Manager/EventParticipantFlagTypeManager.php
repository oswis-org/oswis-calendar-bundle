<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventParticipantFlagTypeManager
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
        ?int $minFlagsAllowed = null,
        ?int $maxFlagsAllowed = null
    ): EventParticipantFlagType {
        try {
            $em = $this->em;
            $entity = new EventParticipantFlagType($nameable, $minFlagsAllowed, $maxFlagsAllowed);
            $em->persist($entity);
            $em->flush();
            $infoMessage = 'CREATE: Created event participant flag type (by manager): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger
                ? $this->logger->info('ERROR: Event event participant flag type not created (by manager): '.$e->getMessage())
                : null;

            return null;
        }
    }
}
