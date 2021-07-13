<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\FlagOfParticipant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\ParticipantFlagOffer;
use OswisOrg\OswisCalendarBundle\Repository\FlagOfParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagOfferRepository;
use Psr\Log\LoggerInterface;

class ParticipantFlagOfferService
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    final public function create(ParticipantFlagOffer $flagRange): ?ParticipantFlagOffer
    {
        try {
            $this->em->persist($flagRange);
            $this->em->flush();
            $infoMessage = 'CREATE: Created flag range (by service): '.$flagRange->getId().' '.$flagRange->getName().'.';
            $this->logger->info($infoMessage);

            return $flagRange;
        } catch (Exception $e) {
            $this->logger->info('ERROR: ParticipantFlag range not created (by service): '.$e->getMessage());

            return null;
        }
    }

    public function updateUsages(Participant $participant): void
    {
        foreach ($participant->getFlagRanges(null, null, false) as $flagRange) {
            $this->updateUsage($flagRange);
        }
    }

    public function updateUsage(ParticipantFlagOffer $flagRange): void
    {
        $usage = $this->countParticipantFlags($flagRange);
        if (null !== $usage) {
            $flagRange->setBaseUsage($usage);
            $flagRange->setFullUsage($usage);
        }
    }

    public function countParticipantFlags(ParticipantFlagOffer $flagRange, bool $includeDeleted = false): ?int
    {
        return $this->getParticipantFlagRepository()->countParticipantFlagGroups(
            [
                FlagOfParticipantRepository::CRITERIA_FLAG_RANGE      => $flagRange,
                FlagOfParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getParticipantFlagRepository(): FlagOfParticipantRepository
    {
        $repo = $this->em->getRepository(FlagOfParticipant::class);
        assert($repo instanceof FlagOfParticipantRepository);

        return $repo;
    }

    public function getParticipantFlags(ParticipantFlagOffer $flagRange, bool $includeDeleted = false): Collection
    {
        return $this->getParticipantFlagRepository()->getParticipantFlagGroups(
            [
                FlagOfParticipantRepository::CRITERIA_FLAG_RANGE      => $flagRange,
                FlagOfParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getRepository(): ParticipantFlagOfferRepository
    {
        $repository = $this->em->getRepository(ParticipantFlagOffer::class);
        assert($repository instanceof ParticipantFlagOfferRepository);

        return $repository;
    }
}
