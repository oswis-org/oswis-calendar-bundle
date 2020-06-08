<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantCategoryRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Psr\Log\LoggerInterface;

class ParticipantCategoryService
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    public function create(?Nameable $nameable = null, ?string $type = null): ?ParticipantCategory
    {
        try {
            $entity = new ParticipantCategory($nameable, $type);
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

    public function getParticipantTypeBySlug(?string $slug, bool $onlyPublic = true): ?ParticipantCategory
    {
        if (empty($slug)) {
            return null;
        }
        $type = $this->getRepository()->getParticipantType(
            [
                ParticipantCategoryRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => $onlyPublic,
                ParticipantCategoryRepository::CRITERIA_SLUG               => $slug,
            ]
        );
        if (null === $type) {
            $type = $this->getRepository()->getParticipantType(
                [
                    ParticipantCategoryRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => $onlyPublic,
                    ParticipantCategoryRepository::CRITERIA_TYPE_OF_TYPE       => $slug,
                ]
            );
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
