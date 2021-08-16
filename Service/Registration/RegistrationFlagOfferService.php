<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service\Registration;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantFlagRepository;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationFlagOfferRepository;
use Psr\Log\LoggerInterface;

class RegistrationFlagOfferService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
    ) {
    }

    final public function create(RegistrationFlagOffer $flagRange): ?RegistrationFlagOffer
    {
        try {
            $this->em->persist($flagRange);
            $this->em->flush();
            $infoMessage = 'CREATE: Created flag range (by service): '.$flagRange->getId().' '.$flagRange->getName().'.';
            $this->logger->info($infoMessage);

            return $flagRange;
        } catch (Exception $e) {
            $this->logger->info('ERROR: RegistrationFlag range not created (by service): '.$e->getMessage());

            return null;
        }
    }

    public function updateUsages(Participant $participant): void
    {
        foreach ($participant->getFlagOffers(null, null, false) as $flagRange) {
            $this->updateUsage($flagRange);
        }
    }

    public function updateUsage(RegistrationFlagOffer $flagRange): void
    {
        $usage = $this->countParticipantFlags($flagRange);
        if (null !== $usage) {
            $flagRange->setBaseUsage($usage);
            $flagRange->setFullUsage($usage);
        }
    }

    public function countParticipantFlags(RegistrationFlagOffer $flagRange, bool $includeDeleted = false): ?int
    {
        return $this->getParticipantFlagRepository()->countParticipantFlagGroups([
            ParticipantFlagRepository::CRITERIA_FLAG_RANGE      => $flagRange,
            ParticipantFlagRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
        ]);
    }

    public function getParticipantFlagRepository(): ParticipantFlagRepository
    {
        $repo = $this->em->getRepository(ParticipantFlag::class);
        assert($repo instanceof ParticipantFlagRepository);

        return $repo;
    }

    public function getParticipantFlags(RegistrationFlagOffer $flagRange, bool $includeDeleted = false): Collection
    {
        return $this->getParticipantFlagRepository()->getParticipantFlagGroups([
            ParticipantFlagRepository::CRITERIA_FLAG_RANGE      => $flagRange,
            ParticipantFlagRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
        ]);
    }

    public function getRepository(): RegistrationFlagOfferRepository
    {
        $repository = $this->em->getRepository(RegistrationFlagOffer::class);
        assert($repository instanceof RegistrationFlagOfferRepository);

        return $repository;
    }
}
