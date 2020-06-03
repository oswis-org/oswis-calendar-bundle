<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRangeConnection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRangeConnectionRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\RegistrationsRangeRepository;
use Psr\Log\LoggerInterface;

class RegistrationsRangeService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    protected ParticipantFlagRangeService $flagRangeService;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger, ParticipantFlagRangeService $flagRangeService)
    {
        $this->em = $em;
        $this->flagRangeService = $flagRangeService;
        $this->logger = $logger;
    }


    final public function create(RegistrationsRange $range): ?RegistrationsRange
    {
        try {
            $this->em->persist($range);
            $this->em->flush();
            $infoMessage = 'CREATE: Created registrations range (by service): '.$range->getId().' '.$range->getName().'.';
            $this->logger->info($infoMessage);

            return $range;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Registrations range not created (by service): '.$e->getMessage());

            return null;
        }
    }


    public function updateUsage(RegistrationsRange $range): void
    {
        $usage = $this->getRegistrationsRangeConnectionsByRange($range, false)->count();
        $range->setBaseUsage($usage);
        $range->setFullUsage($usage);
        foreach ($range->getFlagRanges() as $flagRange) {
            $this->flagRangeService->updateUsage($flagRange);
        }
    }

    public function getRegistrationsRangeConnectionsByRange(RegistrationsRange $range, bool $includeDeleted = false): Collection
    {
        return $this->getParticipantRangeConnectionRepository()->getRangesConnections(
            [
                ParticipantRepository::CRITERIA_RANGE           => $range,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ]
        );
    }

    public function getParticipantRangeConnectionRepository(): ParticipantRangeConnectionRepository
    {
        $repository = $this->em->getRepository(ParticipantRangeConnection::class);
        assert($repository instanceof ParticipantRangeConnectionRepository);

        return $repository;
    }

    public function getRangeBySlug(string $rangeSlug, bool $publicOnWeb = true, bool $onlyActive = true): RegistrationsRange
    {
        return $this->getRepository()->getRegistrationsRange(
            [
                RegistrationsRangeRepository::CRITERIA_SLUG          => $rangeSlug,
                RegistrationsRangeRepository::CRITERIA_ONLY_ACTIVE   => $onlyActive,
                RegistrationsRangeRepository::CRITERIA_PUBLIC_ON_WEB => $publicOnWeb,
            ]
        );
    }

    public function getRepository(): RegistrationsRangeRepository
    {
        $repository = $this->em->getRepository(RegistrationsRange::class);
        assert($repository instanceof RegistrationsRangeRepository);

        return $repository;
    }

    public function getRange(
        Event $event,
        ?ParticipantType $participantType,
        ?string $participantTypeString,
        bool $publicOnWeb = false,
        bool $onlyActive = true
    ): ?RegistrationsRange {
        return $this->getRepository()->getRegistrationsRange(
            [
                RegistrationsRangeRepository::CRITERIA_EVENT                   => $event,
                RegistrationsRangeRepository::CRITERIA_PARTICIPANT_TYPE        => $participantType,
                RegistrationsRangeRepository::CRITERIA_PARTICIPANT_TYPE_STRING => $participantTypeString,
                RegistrationsRangeRepository::CRITERIA_ONLY_ACTIVE             => $onlyActive,
                RegistrationsRangeRepository::CRITERIA_PUBLIC_ON_WEB           => $publicOnWeb,
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
     * @return array [
     *     eventId => ['event' => Event, 'ranges' => Collection<RegistrationsRange>],
     * ]
     */
    public function getEventRegistrationRanges(Collection $events, ?string $participantType = null, bool $onlyPublicOnWeb = true): array
    {
        $ranges = [];
        foreach ($events as $event) {
            if (!($event instanceof Event)) {
                continue;
            }
            $eventRanges = $this->getRepository()->getRegistrationsRanges(
                [
                    RegistrationsRangeRepository::CRITERIA_EVENT                   => $event,
                    RegistrationsRangeRepository::CRITERIA_PARTICIPANT_TYPE_STRING => $participantType,
                    RegistrationsRangeRepository::CRITERIA_PUBLIC_ON_WEB           => $onlyPublicOnWeb,
                ]
            );
            $ranges[$event->getId()] ??= [
                'event'  => $event,
                'ranges' => $eventRanges,
            ];
        }

        return $ranges;
    }


}