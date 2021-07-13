<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CapacityUsage;
use OswisOrg\OswisCalendarBundle\Entity\Participant\OfferOfParticipant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\ParticipantOffer;
use OswisOrg\OswisCalendarBundle\Repository\OfferOfParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantOfferRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
use Psr\Log\LoggerInterface;

class ParticipantOfferService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
        protected ParticipantFlagOfferService $flagRangeService,
    ) {
    }

    final public function create(ParticipantOffer $range): ?ParticipantOffer
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

    public function updateUsage(ParticipantOffer $range): void
    {
        $usage = $this->countRegistrationsRangeConnectionsByRange($range);
        if (null !== $usage) {
            $range->setUsage(new CapacityUsage($usage));
        }
        foreach ($range->getFlagGroupRanges() as $flagRange) {
            $this->flagRangeService->updateUsage($flagRange);
        }
    }

    public function countRegistrationsRangeConnectionsByRange(ParticipantOffer $range, bool $includeDeleted = false): ?int
    {
        return $this->getParticipantRangeConnectionRepository()->countRangesConnections(
            [
                ParticipantRepository::CRITERIA_REG_RANGE       => $range,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getParticipantRangeConnectionRepository(): OfferOfParticipantRepository
    {
        $repository = $this->em->getRepository(OfferOfParticipant::class);
        assert($repository instanceof OfferOfParticipantRepository);

        return $repository;
    }

    public function getRegistrationsRangeConnectionsByRange(ParticipantOffer $range, bool $includeDeleted = false): Collection
    {
        return $this->getParticipantRangeConnectionRepository()->getRangesConnections(
            [
                ParticipantRepository::CRITERIA_REG_RANGE       => $range,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getRangeBySlug(string $rangeSlug, bool $publicOnWeb = true, bool $onlyActive = true): ?ParticipantOffer
    {
        return $this->getRepository()->getRegistrationsRange(
            [
                ParticipantOfferRepository::CRITERIA_SLUG          => $rangeSlug,
                ParticipantOfferRepository::CRITERIA_ONLY_ACTIVE   => $onlyActive,
                ParticipantOfferRepository::CRITERIA_PUBLIC_ON_WEB => $publicOnWeb,
            ]
        );
    }

    public function getRepository(): ParticipantOfferRepository
    {
        $repository = $this->em->getRepository(ParticipantOffer::class);
        assert($repository instanceof ParticipantOfferRepository);

        return $repository;
    }

    public function getRange(
        Event $event,
        ?ParticipantCategory $participantCategory,
        ?string $participantType,
        bool $publicOnWeb = false,
        bool $onlyActive = true
    ): ?ParticipantOffer {
        return $this->getRepository()->getRegistrationsRange(
            [
                ParticipantOfferRepository::CRITERIA_EVENT                => $event,
                ParticipantOfferRepository::CRITERIA_PARTICIPANT_CATEGORY => $participantCategory,
                ParticipantOfferRepository::CRITERIA_PARTICIPANT_TYPE     => $participantType,
                ParticipantOfferRepository::CRITERIA_ONLY_ACTIVE          => $onlyActive,
                ParticipantOfferRepository::CRITERIA_PUBLIC_ON_WEB        => $publicOnWeb,
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
                            ParticipantOfferRepository::CRITERIA_EVENT            => $event,
                            ParticipantOfferRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
                            ParticipantOfferRepository::CRITERIA_PUBLIC_ON_WEB    => $onlyPublicOnWeb,
                        ]
                    ),
                ];
            }
        }

        return new ArrayCollection($ranges);
    }
}