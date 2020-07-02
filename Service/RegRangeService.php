<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CapacityUsage;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRange;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegRange;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRangeRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\RegRangeRepository;
use Psr\Log\LoggerInterface;

class RegRangeService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    protected FlagRangeService $flagRangeService;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger, FlagRangeService $flagRangeService)
    {
        $this->em = $em;
        $this->flagRangeService = $flagRangeService;
        $this->logger = $logger;
    }

    final public function create(RegRange $range): ?RegRange
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

    public function updateUsage(RegRange $range): void
    {
        $range->setUsage(new CapacityUsage($this->getRegistrationsRangeConnectionsByRange($range, false)->count()));
        foreach ($range->getFlagGroupRanges() as $flagRange) {
            $this->flagRangeService->updateUsage($flagRange);
        }
    }

    public function getRegistrationsRangeConnectionsByRange(RegRange $range, bool $includeDeleted = false): Collection
    {
        return $this->getParticipantRangeConnectionRepository()->getRangesConnections(
            [
                ParticipantRepository::CRITERIA_REG_RANGE       => $range,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getParticipantRangeConnectionRepository(): ParticipantRangeRepository
    {
        $repository = $this->em->getRepository(ParticipantRange::class);
        assert($repository instanceof ParticipantRangeRepository);

        return $repository;
    }

    public function getRangeBySlug(string $rangeSlug, bool $publicOnWeb = true, bool $onlyActive = true): ?RegRange
    {
        return $this->getRepository()->getRegistrationsRange(
            [
                RegRangeRepository::CRITERIA_SLUG          => $rangeSlug,
                RegRangeRepository::CRITERIA_ONLY_ACTIVE   => $onlyActive,
                RegRangeRepository::CRITERIA_PUBLIC_ON_WEB => $publicOnWeb,
            ]
        );
    }

    public function getRepository(): RegRangeRepository
    {
        $repository = $this->em->getRepository(RegRange::class);
        assert($repository instanceof RegRangeRepository);

        return $repository;
    }

    public function getRange(
        Event $event,
        ?ParticipantCategory $participantCategory,
        ?string $participantType,
        bool $publicOnWeb = false,
        bool $onlyActive = true
    ): ?RegRange {
        return $this->getRepository()->getRegistrationsRange(
            [
                RegRangeRepository::CRITERIA_EVENT                => $event,
                RegRangeRepository::CRITERIA_PARTICIPANT_CATEGORY => $participantCategory,
                RegRangeRepository::CRITERIA_PARTICIPANT_TYPE     => $participantType,
                RegRangeRepository::CRITERIA_ONLY_ACTIVE          => $onlyActive,
                RegRangeRepository::CRITERIA_PUBLIC_ON_WEB        => $publicOnWeb,
            ]
        );
    }

    /**
     * Helper for getting structured array of registration ranges from given collection of events.
     *
     * @param Collection  $events          Collection of events to extract registration ranges.
     * @param string|null $participantType Restriction to event participant type.
     * @param bool        $onlyPublicOnWeb Restriction only for web-public ranges.
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
                            RegRangeRepository::CRITERIA_EVENT            => $event,
                            RegRangeRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
                            RegRangeRepository::CRITERIA_PUBLIC_ON_WEB    => $onlyPublicOnWeb,
                        ]
                    ),
                ];
            }
        }

        return new ArrayCollection($ranges);
    }
}