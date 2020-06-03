<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRange;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRangeConnectionRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRangeRepository;
use Psr\Log\LoggerInterface;

class ParticipantFlagRangeService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    protected ParticipantFlagRangeRepository $participantFlagRangeRepository;

    protected ParticipantFlagRangeConnectionRepository $flagRangeConnectionRepository;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        ParticipantFlagRangeConnectionRepository $flagRangeConnectionRepository,
        ParticipantFlagRangeRepository $participantFlagRangeRepository
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->flagRangeConnectionRepository = $flagRangeConnectionRepository;
        $this->participantFlagRangeRepository = $participantFlagRangeRepository;
    }

    public function updateUsage(ParticipantFlagRange $range): void
    {
        $usage = $this->getFlagRangeConnectionsByRange($range, false)->count();
        $range->setUsage($usage);
    }

    public function getFlagRangeConnectionsByRange(ParticipantFlagRange $flagRange, bool $includeDeleted = false): Collection
    {
        return $this->getFlagRangeConnectionRepository()->getFlagRangesConnections(
            [
                ParticipantFlagRangeConnectionRepository::CRITERIA_FLAG_RANGE      => $flagRange,
                ParticipantFlagRangeConnectionRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getFlagRangeConnectionRepository(): ParticipantFlagRangeConnectionRepository
    {
        return $this->flagRangeConnectionRepository;
    }

    public function getRepository(): ParticipantFlagRangeRepository
    {
        $repository = $this->em->getRepository(ParticipantFlagRange::class);
        assert($repository instanceof ParticipantFlagRangeRepository);

        return $repository;
    }
}