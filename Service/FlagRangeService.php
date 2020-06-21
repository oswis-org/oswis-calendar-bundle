<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use OswisOrg\OswisCalendarBundle\Entity\Participant\RegistrationsFlagRange;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagRange;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagGroupRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRangeRepository;
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

    public function updateUsage(FlagRange $range): void
    {
        $usage = $this->getFlagRangeConnectionsByRange($range, false)->count();
        $range->setBaseUsage($usage);
    }

    public function getFlagRangeConnectionsByRange(FlagRange $flagRange, bool $includeDeleted = false): Collection
    {
        return $this->getFlagRangeConnectionRepository()->getFlagRangesConnections(
            [
                ParticipantFlagGroupRepository::CRITERIA_FLAG_RANGE      => $flagRange,
                ParticipantFlagGroupRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getFlagRangeConnectionRepository(): ParticipantFlagGroupRepository
    {
        $repo = $this->em->getRepository(ParticipantFlagGroup::class);
        assert($repo instanceof ParticipantFlagGroupRepository);

        return $repo;
    }

    public function getRepository(): ParticipantFlagRangeRepository
    {
        $repository = $this->em->getRepository(RegistrationsFlagRange::class);
        assert($repository instanceof ParticipantFlagRangeRepository);

        return $repository;
    }
}