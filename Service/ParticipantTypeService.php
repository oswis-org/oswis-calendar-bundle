<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantTypeRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Psr\Log\LoggerInterface;

class ParticipantTypeService
{
    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    public function getRepository(): ParticipantTypeRepository
    {
        $repository = $this->em->getRepository(ParticipantType::class);
        assert($repository instanceof ParticipantTypeRepository);

        return $repository;
    }

    public function create(?Nameable $nameable = null, ?string $type = null): ?ParticipantType
    {
        try {
            $entity = new ParticipantType($nameable, $type);
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

    public function getParticipantTypeBySlug(?string $slug, bool $onlyPublic = true): ?ParticipantType
    {
        if (empty($slug)) {
            return null;
        }
        $type = $this->getRepository()->getParticipantType(
            [
                ParticipantTypeRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => $onlyPublic,
                ParticipantTypeRepository::CRITERIA_SLUG               => $slug,
            ]
        );
        if (null === $type) {
            $type = $this->getRepository()->getParticipantType(
                [
                    ParticipantTypeRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => $onlyPublic,
                    ParticipantTypeRepository::CRITERIA_TYPE_OF_TYPE       => $slug,
                ]
            );
        }

        return $type;
    }

}
