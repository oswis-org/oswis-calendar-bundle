<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventAttendeeFlagManager
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
        ?bool $public = null,
        ?bool $valueAllowed = null,
        ?string $valueLabel = null
    ): EventAttendeeFlag {
        try {
            $em = $this->em;
            $entity = new EventAttendeeFlag(
                $nameable,
                $public,
                $valueAllowed,
                $valueLabel
            );
            $em->persist($entity);
            $em->flush();
            $infoMessage = 'Created event attendee flag: '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (\Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event attendee flag not created: '.$e->getMessage()) : null;

            return null;
        }
    }
}
