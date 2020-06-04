<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRangeConnection;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRangeConnectionRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRangeRepository;

class ParticipantFlagRangeService
{
    protected EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function updateUsage(ParticipantFlagRange $range): void
    {
        $usage = $this->getFlagRangeConnectionsByRange($range, false)->count();
        $range->setBaseUsage($usage);
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
        $repo = $this->em->getRepository(ParticipantFlagRangeConnection::class);
        assert($repo instanceof ParticipantFlagRangeConnectionRepository);

        return $repo;
    }

    public function getRepository(): ParticipantFlagRangeRepository
    {
        $repository = $this->em->getRepository(ParticipantFlagRange::class);
        assert($repository instanceof ParticipantFlagRangeRepository);

        return $repository;
    }
}