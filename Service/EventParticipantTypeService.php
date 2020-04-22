<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use OswisOrg\OswisCalendarBundle\Repository\EventParticipantTypeRepository;
use OswisOrg\OswisCoreBundle\Entity\Nameable;
use Psr\Log\LoggerInterface;

class EventParticipantTypeService
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    public function getRepository(): EventParticipantTypeRepository
    {
        $repository = $this->em->getRepository(EventParticipantType::class);
        assert($repository instanceof EventParticipantTypeRepository);

        return $repository;
    }

    public function create(?Nameable $nameable = null, ?string $type = null): ?EventParticipantType
    {
        try {
            $entity = new EventParticipantType($nameable, $type);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event participant type (by service): '.$entity->getId().' '.$entity->getName().'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event participant type not created (by service): '.$e->getMessage()) : null;

            return null;
        }
    }
}
