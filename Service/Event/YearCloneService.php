<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Event;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventContent;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventFlagConnection;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\OfferOverride;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\YearCloneRequest;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagOverride;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagGroupOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Exception\DuplicateSlugException;
use OswisOrg\OswisCalendarBundle\Exception\YearCloneDryRunCompleteException;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationOfferRepository;
use Psr\Log\LoggerInterface;

// RegistrationOfferRepository is not registered as an autowired service
// (extends Doctrine\ORM\EntityRepository, not ServiceEntityRepository).
// Fetch lazily via $em->getRepository(RegistrationOffer::class) below.

/**
 * Clones a complete year of a Seznamovák-style event programme into
 * a new year: the YEAR_OF_EVENT root, its BATCH_OF_EVENT turnuses,
 * optionally all sub-activities, plus the full RegistrationOffer +
 * RegistrationFlagGroupOffer + RegistrationFlagOffer tree.
 *
 * Spec: docs/superpowers/specs/2026-05-21-S5-year-clone-wizard-design.md
 */
final class YearCloneService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    private function offerRepository(): RegistrationOfferRepository
    {
        $repo = $this->em->getRepository(RegistrationOffer::class);
        assert($repo instanceof RegistrationOfferRepository);

        return $repo;
    }

    public function cloneYear(YearCloneRequest $request): Event
    {
        $this->validate($request);

        return $this->em->wrapInTransaction(function () use ($request): Event {
            $summary = [
                'event_count'            => 0,
                'event_slugs'            => [],
                'offer_count'            => 0,
                'offer_slugs'            => [],
                'flag_group_offer_count' => 0,
                'flag_offer_count'       => 0,
            ];
            $cloned = $this->doClone($request, $summary);
            if ($request->dryRun) {
                throw new YearCloneDryRunCompleteException($summary);
            }

            return $cloned;
        });
    }

    /**
     * Substitute the four-digit source year token in a slug with the target year.
     * Lookbehind/lookahead on non-digit so `kemp-2025-2` → `kemp-2026-2` and
     * standalone `2025` strings substitute, but `12025` or `20254` do not.
     * Falls back to appending `-{targetYear}` if no source-year token is found.
     */
    public function substituteYearInSlug(string $sourceSlug, int $sourceYear, int $targetYear): string
    {
        $pattern = sprintf('/(?<!\d)(%d)(?!\d)/', $sourceYear);
        $replaced = preg_replace($pattern, (string) $targetYear, $sourceSlug, 1, $count);
        if ($count > 0 && is_string($replaced)) {
            return $replaced;
        }

        return $sourceSlug.'-'.$targetYear;
    }

    /**
     * @throws DuplicateSlugException
     * @throws \InvalidArgumentException
     */
    private function validate(YearCloneRequest $request): void
    {
        if ($request->sourceYearEvent->getCategory()?->getType() !== EventCategory::YEAR_OF_EVENT) {
            throw new \InvalidArgumentException(
                'Source event must be a YEAR_OF_EVENT, got: '.($request->sourceYearEvent->getCategory()?->getType() ?? 'null'),
            );
        }
        if ($request->targetYearStartDate >= $request->targetYearEndDate) {
            throw new \InvalidArgumentException('targetYearStartDate must be before targetYearEndDate.');
        }
        if ($request->sourceYearEvent->getStartYear() === (int) $request->targetYearStartDate->format('Y')) {
            throw new \InvalidArgumentException('Cannot clone a year onto itself.');
        }
        $existing = $this->eventRepository->findOneBy(['slug' => $request->targetYearSlug]);
        if (null !== $existing) {
            throw new DuplicateSlugException($request->targetYearSlug);
        }
    }

    /**
     * Walk the source year and clone everything per spec.
     *
     * @param array{event_count: int, event_slugs: list<string>, offer_count: int, offer_slugs: list<string>, flag_group_offer_count: int, flag_offer_count: int} $summary mutated in place
     */
    private function doClone(YearCloneRequest $request, array &$summary): Event
    {
        $source = $request->sourceYearEvent;
        $sourceStart = $source->getStartDateTime() ?? throw new \LogicException('Source year missing startDateTime.');
        $targetStart = $request->targetYearStartDate;
        $sourceYear = (int) $sourceStart->format('Y');
        $targetYear = (int) $targetStart->format('Y');

        $newYear = $this->cloneEventShallow(
            $source,
            $request->targetYearSlug,
            $request->targetYearName,
            $request->targetYearStartDate,
            $request->targetYearEndDate,
            null,
        );
        $this->em->persist($newYear);
        $summary['event_count']++;
        $summary['event_slugs'][] = $newYear->getSlug();
        $this->logger->info('YearCloneService: cloned year "{slug}".', ['slug' => $newYear->getSlug()]);

        $timeOffsetSeconds = $targetStart->getTimestamp() - $sourceStart->getTimestamp();
        $eventCloneMap = [$source->getSlug() => $newYear];
        $this->cloneSubtreeRecursively(
            $source,
            $newYear,
            $sourceYear,
            $targetYear,
            $timeOffsetSeconds,
            $request->cloneSubActivities,
            $eventCloneMap,
            $summary,
        );

        $offerCloneMap = $this->cloneRegistrationOffersPass1(
            $source,
            $eventCloneMap,
            $sourceYear,
            $targetYear,
            $timeOffsetSeconds,
            $request->offerOverrides,
            $summary,
        );
        $this->remapRequiredRegRanges($source, $eventCloneMap, $offerCloneMap);
        $this->cloneFlagTree($source, $offerCloneMap, $sourceYear, $targetYear, $request->flagOverrides, $summary);

        return $newYear;
    }

    /**
     * For every cloned RegistrationOffer, clone its FlagGroupOffer rows
     * and the underlying FlagOffer rows. Categories and Flag taxonomy
     * entities are referenced, not cloned (shared across years).
     *
     * @param array<int, RegistrationOffer> $offerCloneMap keyed by source RegistrationOffer.id
     * @param array<int, FlagOverride>      $flagOverrides keyed by source RegistrationFlagOffer.id
     * @param array{event_count: int, event_slugs: list<string>, offer_count: int, offer_slugs: list<string>, flag_group_offer_count: int, flag_offer_count: int} $summary mutated in place
     */
    private function cloneFlagTree(
        Event $sourceRoot,
        array $offerCloneMap,
        int $sourceYear,
        int $targetYear,
        array $flagOverrides,
        array &$summary,
    ): void {
        $sourceEvents = $this->collectSourceEventsByOriginalSlug($sourceRoot);
        foreach ($sourceEvents as $sourceEvent) {
            $sourceOffers = $this->offerRepository()->getRegistrationsRanges(
                [RegistrationOfferRepository::CRITERIA_EVENT => $sourceEvent],
            );
            foreach ($sourceOffers as $sourceOffer) {
                if (!$sourceOffer instanceof RegistrationOffer) {
                    continue;
                }
                $sourceOfferId = $sourceOffer->getId();
                if (null === $sourceOfferId) {
                    continue;
                }
                $clonedOffer = $offerCloneMap[$sourceOfferId] ?? null;
                if (null === $clonedOffer) {
                    continue;
                }
                foreach ($sourceOffer->getFlagGroupRanges() as $sourceFlagGroupOffer) {
                    $clonedFlagGroupOffer = $this->cloneRegistrationFlagGroupOffer(
                        $sourceFlagGroupOffer,
                        $sourceYear,
                        $targetYear,
                        $flagOverrides,
                        $summary,
                    );
                    $this->em->persist($clonedFlagGroupOffer);
                    $clonedOffer->addFlagGroupRange($clonedFlagGroupOffer);
                    $summary['flag_group_offer_count']++;
                }
            }
        }
    }

    /**
     * @param array<int, FlagOverride> $flagOverrides keyed by source RegistrationFlagOffer.id
     * @param array{event_count: int, event_slugs: list<string>, offer_count: int, offer_slugs: list<string>, flag_group_offer_count: int, flag_offer_count: int} $summary mutated in place
     */
    private function cloneRegistrationFlagGroupOffer(
        RegistrationFlagGroupOffer $source,
        int $sourceYear,
        int $targetYear,
        array $flagOverrides,
        array &$summary,
    ): RegistrationFlagGroupOffer {
        $clone = new RegistrationFlagGroupOffer(
            $source->getFlagCategory(),
            $source->getFlagAmountRange(),
            null,
            $source->getEmptyPlaceholder(),
        );
        $clone->setName($source->getName() ?? '');
        $clone->setShortName($source->getShortName());
        $clone->setDescription($source->getDescription());
        $clone->setNote($source->getNote());
        $clone->setInternalNote($source->getInternalNote());
        $clone->setForcedSlug($this->substituteYearInSlug($source->getSlug(), $sourceYear, $targetYear));
        $clone->setPublicOnWeb(false);
        $clone->setPublicInApp(false);

        foreach ($source->getFlagOffers() as $sourceFlagOffer) {
            $sourceFlagOfferId = $sourceFlagOffer->getId();
            $override = (null !== $sourceFlagOfferId) ? ($flagOverrides[$sourceFlagOfferId] ?? null) : null;
            $clonedFlagOffer = $this->cloneRegistrationFlagOffer(
                $sourceFlagOffer,
                $sourceYear,
                $targetYear,
                $override,
            );
            $clone->addFlagRange($clonedFlagOffer);
            $summary['flag_offer_count']++;
        }

        return $clone;
    }

    private function cloneRegistrationFlagOffer(
        RegistrationFlagOffer $source,
        int $sourceYear,
        int $targetYear,
        ?FlagOverride $override,
    ): RegistrationFlagOffer {
        $sourcePrice = $source->getPrice();
        $sourceDeposit = $source->getDepositValue();
        $overridePrice = $override === null ? null : $override->price;
        $overrideBaseCapacity = $override === null ? null : $override->baseCapacity;
        $overrideFullCapacity = $override === null ? null : $override->fullCapacity;
        $finalPrice = $overridePrice ?? $sourcePrice;
        $finalBaseCapacity = $overrideBaseCapacity ?? $source->getBaseCapacity();
        $finalFullCapacity = $overrideFullCapacity ?? $source->getFullCapacity();

        $clone = new RegistrationFlagOffer(
            $source->getFlag(),
            new Capacity($finalBaseCapacity, $finalFullCapacity),
            new Price($finalPrice, $sourceDeposit),
            $source->getFlagAmountRange(),
            null,
        );
        $clone->setName($source->getName() ?? '');
        $clone->setShortName($source->getShortName());
        $clone->setDescription($source->getDescription());
        $clone->setNote($source->getNote());
        $clone->setInternalNote($source->getInternalNote());
        $clone->setForcedSlug($this->substituteYearInSlug($source->getSlug(), $sourceYear, $targetYear));
        $clone->setPublicOnWeb(false);
        $clone->setPublicInApp(false);
        $clone->setBaseUsage(0);
        $clone->setFullUsage(0);

        return $clone;
    }

    /**
     * @param array<string, Event> $eventCloneMap keyed by source slug; mutated in-place
     * @param array{event_count: int, event_slugs: list<string>, offer_count: int, offer_slugs: list<string>, flag_group_offer_count: int, flag_offer_count: int} $summary mutated in place
     */
    private function cloneSubtreeRecursively(
        Event $source,
        Event $newParent,
        int $sourceYear,
        int $targetYear,
        int $timeOffsetSeconds,
        bool $cloneSubActivities,
        array &$eventCloneMap,
        array &$summary,
    ): void {
        foreach ($source->getSubEvents() as $sourceChild) {
            $categoryType = $sourceChild->getCategory()?->getType();
            $isBatch = $categoryType === EventCategory::BATCH_OF_EVENT;
            $isYear = $categoryType === EventCategory::YEAR_OF_EVENT;

            // Never clone nested YEAR_OF_EVENT — that would imply weird data.
            if ($isYear) {
                $this->logger->warning('Skipping nested YEAR_OF_EVENT sub-event: {slug}', ['slug' => $sourceChild->getSlug()]);
                continue;
            }
            // Batches always clone; sub-activities only if requested.
            if (!$isBatch && !$cloneSubActivities) {
                continue;
            }

            $childStart = $sourceChild->getStartDateTime();
            $childEnd = $sourceChild->getEndDateTime();
            if (null === $childStart || null === $childEnd) {
                $this->logger->warning('Skipping child with missing dates: {slug}', ['slug' => $sourceChild->getSlug()]);
                continue;
            }
            $newChildStart = (new \DateTimeImmutable())->setTimestamp($childStart->getTimestamp() + $timeOffsetSeconds);
            $newChildEnd = (new \DateTimeImmutable())->setTimestamp($childEnd->getTimestamp() + $timeOffsetSeconds);

            $newChild = $this->cloneEventShallow(
                $sourceChild,
                $this->substituteYearInSlug($sourceChild->getSlug(), $sourceYear, $targetYear),
                $sourceChild->getName() ?? '',
                $newChildStart,
                $newChildEnd,
                $newParent,
            );
            $this->em->persist($newChild);
            $eventCloneMap[$sourceChild->getSlug()] = $newChild;
            $summary['event_count']++;
            $summary['event_slugs'][] = $newChild->getSlug();

            // Recurse: sub-activities can themselves have sub-activities (program structure).
            $this->cloneSubtreeRecursively(
                $sourceChild,
                $newChild,
                $sourceYear,
                $targetYear,
                $timeOffsetSeconds,
                $cloneSubActivities,
                $eventCloneMap,
                $summary,
            );
        }
    }

    /**
     * Pass 7a — clone every source RegistrationOffer attached to any
     * cloned event, with usage counters reset and requiredRegRange left
     * null. Pass 7b (remapRequiredRegRanges) wires the parent chain.
     *
     * @param array<string, Event>          $eventCloneMap  keyed by source slug
     * @param array<int, OfferOverride>     $offerOverrides keyed by source RegistrationOffer.id
     * @param array{event_count: int, event_slugs: list<string>, offer_count: int, offer_slugs: list<string>, flag_group_offer_count: int, flag_offer_count: int} $summary mutated in place
     * @return array<int, RegistrationOffer>                keyed by source RegistrationOffer.id
     */
    private function cloneRegistrationOffersPass1(
        Event $sourceRoot,
        array $eventCloneMap,
        int $sourceYear,
        int $targetYear,
        int $timeOffsetSeconds,
        array $offerOverrides,
        array &$summary,
    ): array {
        $offerCloneMap = [];
        $sourceEvents = $this->collectSourceEventsByOriginalSlug($sourceRoot);
        foreach ($sourceEvents as $sourceSlug => $sourceEvent) {
            $newEvent = $eventCloneMap[$sourceSlug] ?? null;
            if (null === $newEvent) {
                continue; // sub-activity skipped because cloneSubActivities=false
            }
            $sourceOffers = $this->offerRepository()->getRegistrationsRanges(
                [RegistrationOfferRepository::CRITERIA_EVENT => $sourceEvent],
            );
            foreach ($sourceOffers as $sourceOffer) {
                if (!$sourceOffer instanceof RegistrationOffer) {
                    continue;
                }
                $sourceOfferId = $sourceOffer->getId();
                if (null === $sourceOfferId) {
                    continue;
                }
                $clone = $this->cloneRegistrationOfferShallow(
                    $sourceOffer,
                    $newEvent,
                    $sourceYear,
                    $targetYear,
                    $timeOffsetSeconds,
                    $offerOverrides[$sourceOfferId] ?? null,
                );
                $this->em->persist($clone);
                $offerCloneMap[$sourceOfferId] = $clone;
                $summary['offer_count']++;
                $summary['offer_slugs'][] = $clone->getSlug();
            }
        }

        return $offerCloneMap;
    }

    /**
     * Pass 7b — wire requiredRegRange on cloned offers to the cloned parent.
     *
     * @param array<string, Event>          $eventCloneMap
     * @param array<int, RegistrationOffer> $offerCloneMap keyed by source RegistrationOffer.id
     */
    private function remapRequiredRegRanges(Event $sourceRoot, array $eventCloneMap, array $offerCloneMap): void
    {
        $sourceEvents = $this->collectSourceEventsByOriginalSlug($sourceRoot);
        foreach ($sourceEvents as $sourceSlug => $sourceEvent) {
            if (!isset($eventCloneMap[$sourceSlug])) {
                continue;
            }
            $sourceOffers = $this->offerRepository()->getRegistrationsRanges(
                [RegistrationOfferRepository::CRITERIA_EVENT => $sourceEvent],
            );
            foreach ($sourceOffers as $sourceOffer) {
                if (!$sourceOffer instanceof RegistrationOffer) {
                    continue;
                }
                $sourceParent = $sourceOffer->getRequiredRegRange();
                if (null === $sourceParent) {
                    continue;
                }
                $sourceOfferId = $sourceOffer->getId();
                $sourceParentId = $sourceParent->getId();
                if (null === $sourceOfferId || null === $sourceParentId) {
                    continue;
                }
                $clone = $offerCloneMap[$sourceOfferId] ?? null;
                if (null === $clone) {
                    continue;
                }
                $parentClone = $offerCloneMap[$sourceParentId] ?? null;
                if (null === $parentClone) {
                    $this->logger->warning(
                        'requiredRegRange remap: source offer {id} has a parent ({parent_id}) outside the cloned set; leaving null on the clone.',
                        ['id' => $sourceOfferId, 'parent_id' => $sourceParentId],
                    );
                    continue;
                }
                $clone->setRequiredRegRange($parentClone);
            }
        }
    }

    /**
     * @return array<string, Event> keyed by source slug, all source events transitively
     */
    private function collectSourceEventsByOriginalSlug(Event $root): array
    {
        $map = [];
        $stack = [$root];
        while ($current = array_pop($stack)) {
            $map[$current->getSlug()] = $current;
            foreach ($current->getSubEvents() as $child) {
                $stack[] = $child;
            }
        }

        return $map;
    }

    private function cloneRegistrationOfferShallow(
        RegistrationOffer $source,
        Event $newEvent,
        int $sourceYear,
        int $targetYear,
        int $timeOffsetSeconds,
        ?OfferOverride $override,
    ): RegistrationOffer {
        $clone = new RegistrationOffer();
        $clone->setName($source->getName() ?? '');
        $clone->setForcedSlug($this->substituteYearInSlug($source->getSlug(), $sourceYear, $targetYear));
        $clone->setShortName($source->getShortName());
        $clone->setDescription($source->getDescription());
        $clone->setNote($source->getNote());
        $clone->setInternalNote($source->getInternalNote());
        $clone->setEvent($newEvent);
        $clone->setParticipantCategory($source->getParticipantCategory());
        $clone->setPriority($source->getPriority());
        $clone->setRelative($source->isRelative());
        $clone->setSurrogate($source->isSurrogate());
        $clone->setSuperEventRequired($source->isSuperEventRequired());
        $clone->setRequiredRegRange(null); // remap in Pass 7b
        $clone->setPublicOnWeb(false);
        $clone->setPublicInApp(false);
        // Reset per-year usage counters; capacity comes from source/override below.
        $clone->setBaseUsage(0);
        $clone->setFullUsage(0);

        // Dates (registration window): shift by offset, then apply override.
        $sourceStart = $source->getStartDateTime();
        $sourceEnd = $source->getEndDate();
        if (null !== $sourceStart) {
            $clone->setStartDateTime(new \DateTime('@'.($sourceStart->getTimestamp() + $timeOffsetSeconds)));
        }
        if (null !== $sourceEnd) {
            $clone->setEndDateTime(new \DateTime('@'.($sourceEnd->getTimestamp() + $timeOffsetSeconds)), true);
        }

        // Price + capacity: source values; override wins if provided.
        $sourcePrice = $source->getPrice();
        $sourceDeposit = $source->getDepositValue();
        $overridePrice = $override === null ? null : $override->price;
        $overrideDeposit = $override === null ? null : $override->depositValue;
        $overrideBaseCapacity = $override === null ? null : $override->baseCapacity;
        $overrideFullCapacity = $override === null ? null : $override->fullCapacity;

        $finalPrice = $overridePrice ?? $sourcePrice;
        $finalDeposit = $overrideDeposit ?? $sourceDeposit;
        $clone->setEventPrice(new Price($finalPrice, $finalDeposit));

        $finalBaseCapacity = $overrideBaseCapacity ?? $source->getBaseCapacity();
        $finalFullCapacity = $overrideFullCapacity ?? $source->getFullCapacity();
        $clone->setCapacity(new Capacity($finalBaseCapacity, $finalFullCapacity));

        if (null !== $override?->startDateTime) {
            $clone->setStartDateTime(\DateTime::createFromInterface($override->startDateTime));
        }
        if (null !== $override?->endDateTime) {
            $clone->setEndDateTime(\DateTime::createFromInterface($override->endDateTime), true);
        }

        return $clone;
    }

    /**
     * Clone the basic Event fields. RegistrationOffers and metadata
     * (contents, flag connections, images, files) handled in subsequent
     * tasks.
     */
    private function cloneEventShallow(
        Event $source,
        string $newSlug,
        string $newName,
        \DateTimeInterface $newStart,
        \DateTimeInterface $newEnd,
        ?Event $newSuperEvent,
    ): Event {
        $clone = new Event();
        $clone->setName($newName);
        $clone->setForcedSlug($newSlug);
        $clone->setShortName($source->getShortName());
        $clone->setDescription($source->getDescription());
        $clone->setNote($source->getNote());
        $clone->setInternalNote($source->getInternalNote());
        $clone->setStartDateTime(\DateTime::createFromInterface($newStart));
        $clone->setEndDateTime(\DateTime::createFromInterface($newEnd));
        $clone->setColor($source->getColor());
        $clone->setPlace($source->getPlace(false));
        $clone->setOrganizer($source->getOrganizer(false));
        $clone->setCategory($source->getCategory());
        $clone->setGroup($source->getGroup());
        $clone->setSuperEvent($newSuperEvent);
        $clone->setPublicOnWeb(false);
        $clone->setPublicInApp(false);

        // EventContent: text values copied verbatim. Admin reviews + edits later.
        // EventImage / EventFile intentionally skipped — orphanRemoval on the source
        // would (cascade) delete shared filesystem files if the clone were later removed.
        foreach ($source->getContents() as $sourceContent) {
            $clonedContent = new EventContent(null, $sourceContent->getTextValue(), $sourceContent->getType());
            $clone->addContent($clonedContent);
        }

        // EventFlagConnection: tags carry over (EventFlag taxonomy is shared).
        foreach ($source->getFlagConnections() as $sourceConnection) {
            if (!$sourceConnection instanceof EventFlagConnection) {
                continue;
            }
            $clonedConnection = new EventFlagConnection($sourceConnection->getEventFlag(), $sourceConnection->getTextValue());
            $clone->addFlagConnection($clonedConnection);
        }

        return $clone;
    }

}
