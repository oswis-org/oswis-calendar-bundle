<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CapacityUsage;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRegistration;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRegistrationRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationOfferRepository;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationFlagOfferService;
use Psr\Log\LoggerInterface;

class ParticipantRegistrationService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
        protected RegistrationFlagOfferService $flagRangeService,
    ) {
    }

    final public function create(RegistrationOffer $range): ?RegistrationOffer
    {
        try {
            $this->em->persist($range);
            $this->em->flush();
            $this->logger->info('CREATE: Created registrations range (by service): '.$range->getId().' '.$range->getName().'.');

            return $range;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Registrations range not created (by service): '.$e->getMessage());

            return null;
        }
    }

    public function updateUsage(RegistrationOffer $range): void
    {
        $usage = $this->countRegistrationsRangeConnectionsByRange($range);
        if (null !== $usage) {
            $range->setUsage(new CapacityUsage($usage));
        }
        foreach ($range->getFlagGroupRanges() as $flagRange) {
            $this->flagRangeService->updateUsage($flagRange);
        }
    }

    public function countRegistrationsRangeConnectionsByRange(RegistrationOffer $range, bool $includeDeleted = false): ?int
    {
        return $this->getParticipantRangeConnectionRepository()->countRangesConnections(
            [
                ParticipantRepository::CRITERIA_REG_RANGE       => $range,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getParticipantRangeConnectionRepository(): ParticipantRegistrationRepository
    {
        $repository = $this->em->getRepository(ParticipantRegistration::class);
        assert($repository instanceof ParticipantRegistrationRepository);

        return $repository;
    }

    public function getRegistrationsRangeConnectionsByRange(RegistrationOffer $range, bool $includeDeleted = false): Collection
    {
        return $this->getParticipantRangeConnectionRepository()->getRangesConnections(
            [
                ParticipantRepository::CRITERIA_REG_RANGE       => $range,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getRangeBySlug(string $rangeSlug, bool $publicOnWeb = true, bool $onlyActive = true): ?RegistrationOffer
    {
        return $this->getRepository()->getRegistrationsRange(
            [
                RegistrationOfferRepository::CRITERIA_SLUG          => $rangeSlug,
                RegistrationOfferRepository::CRITERIA_ONLY_ACTIVE   => $onlyActive,
                RegistrationOfferRepository::CRITERIA_PUBLIC_ON_WEB => $publicOnWeb,
            ]
        );
    }

    public function getRepository(): RegistrationOfferRepository
    {
        $repository = $this->em->getRepository(RegistrationOffer::class);
        assert($repository instanceof RegistrationOfferRepository);

        return $repository;
    }

    public function getRange(
        Event $event,
        ?ParticipantCategory $participantCategory,
        ?string $participantType,
        bool $publicOnWeb = false,
        bool $onlyActive = true
    ): ?RegistrationOffer {
        return $this->getRepository()->getRegistrationsRange(
            [
                RegistrationOfferRepository::CRITERIA_EVENT                => $event,
                RegistrationOfferRepository::CRITERIA_PARTICIPANT_CATEGORY => $participantCategory,
                RegistrationOfferRepository::CRITERIA_PARTICIPANT_TYPE     => $participantType,
                RegistrationOfferRepository::CRITERIA_ONLY_ACTIVE          => $onlyActive,
                RegistrationOfferRepository::CRITERIA_PUBLIC_ON_WEB        => $publicOnWeb,
            ]
        );
    }

    /**
     * Helper for getting structured array of registration ranges from given collection of events.
     *
     * @param  Collection  $events  Collection of events to extract registration ranges.
     * @param  string|null  $participantType  Restriction to event participant type.
     * @param  bool  $onlyPublicOnWeb  Restriction only for web-public ranges.
     *
     * @return Collection
     */
    public function getEventRegistrationRanges(Collection $events, ?string $participantType = null, bool $onlyPublicOnWeb = true): Collection
    {
        $ranges = [];
        foreach ($events as $event) {
            if ($event instanceof Event) {
                $ranges = [
                    ...$ranges,
                    ...$this->getRepository()->getRegistrationsRanges(
                        [
                            RegistrationOfferRepository::CRITERIA_EVENT            => $event,
                            RegistrationOfferRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
                            RegistrationOfferRepository::CRITERIA_PUBLIC_ON_WEB    => $onlyPublicOnWeb,
                        ]
                    ),
                ];
            }
        }

        return new ArrayCollection($ranges);
    }
}