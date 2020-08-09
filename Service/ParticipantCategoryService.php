<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantCategoryRepository;
use Psr\Log\LoggerInterface;

class ParticipantCategoryService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    public function create(ParticipantCategory $participantCategory): ?ParticipantCategory
    {
        try {
            $this->em->persist($participantCategory);
            $this->em->flush();
            $this->logger->info('CREATE: Created event participant type (by service): '.$participantCategory->getId().' '.$participantCategory->getName().'.');

            return $participantCategory;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Event event participant type not created (by service): '.$e->getMessage());

            return null;
        }
    }

    public function getParticipantTypeBySlug(?string $slug): ?ParticipantCategory
    {
        if (empty($slug)) {
            return null;
        }
        $type = $this->getRepository()->getParticipantCategory([ParticipantCategoryRepository::CRITERIA_SLUG => $slug]);
        if (null === $type) {
            $type = $this->getRepository()->getParticipantCategory([ParticipantCategoryRepository::CRITERIA_TYPE => $slug]);
        }

        return $type;
    }

    public function getRepository(): ParticipantCategoryRepository
    {
        $repository = $this->em->getRepository(ParticipantCategory::class);
        assert($repository instanceof ParticipantCategoryRepository);

        return $repository;
    }

}
