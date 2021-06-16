<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagRange;
use OswisOrg\OswisCalendarBundle\Repository\FlagRangeRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRepository;
use Psr\Log\LoggerInterface;

class FlagRangeService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    final public function create(FlagRange $flagRange): ?FlagRange
    {
        try {
            $this->em->persist($flagRange);
            $this->em->flush();
            $infoMessage = 'CREATE: Created flag range (by service): '.$flagRange->getId().' '.$flagRange->getName().'.';
            $this->logger->info($infoMessage);

            return $flagRange;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Flag range not created (by service): '.$e->getMessage());

            return null;
        }
    }

    public function updateUsages(Participant $participant): void
    {
        foreach ($participant->getFlagRanges(null, null, false) as $flagRange) {
            $this->updateUsage($flagRange);
        }
    }

    public function updateUsage(FlagRange $flagRange): void
    {
        $usage = $this->getParticipantFlags($flagRange)->count();
        $flagRange->setBaseUsage($usage);
        $flagRange->setFullUsage($usage);
    }

    public function getParticipantFlags(FlagRange $flagRange, bool $includeDeleted = false): Collection
    {
        return $this->getParticipantFlagRepository()->getParticipantFlagGroups(
            [
                ParticipantFlagRepository::CRITERIA_FLAG_RANGE      => $flagRange,
                ParticipantFlagRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getParticipantFlagRepository(): ParticipantFlagRepository
    {
        $repo = $this->em->getRepository(ParticipantFlag::class);
        assert($repo instanceof ParticipantFlagRepository);

        return $repo;
    }

    public function getRepository(): FlagRangeRepository
    {
        $repository = $this->em->getRepository(FlagRange::class);
        assert($repository instanceof FlagRangeRepository);

        return $repository;
    }
}
