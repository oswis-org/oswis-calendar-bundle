<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventSeriesManager
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
        ?Nameable $nameable = null
    ): EventSeries {
        try {
            $em = $this->em;
            $entity = new EventSeries(
                $nameable
            );
            $em->persist($entity);
            $em->flush();
            $infoMessage = 'CREATE: Created event series (by manager): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event series not created (by manager): '.$e->getMessage()) : null;

            return null;
        }
    }
}
