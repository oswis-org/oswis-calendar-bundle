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
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\SubEventOverride;
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

    /**
     * Look up an Event by slug while bypassing the Gedmo soft-delete filter.
     * Soft-deleted rows still occupy their slug logically (no UNIQUE constraint
     * exists), so re-cloning a year after the previous clone was soft-deleted
     * would otherwise produce two rows with the same slug.
     */
    private function findEventBySlugIncludingDeleted(string $slug): ?Event
    {
        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('softdeleteable');
        if ($wasEnabled) {
            $filters->disable('softdeleteable');
        }
        try {
            return $this->eventRepository->findOneBy(['slug' => $slug]);
        } finally {
            if ($wasEnabled) {
                $filters->enable('softdeleteable');
            }
        }
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
     * Substitute the four-digit source year token with the target year inside
     * any string (slug, name, short name, description). Lookbehind/lookahead
     * on non-digit so `kemp-2025-2` → `kemp-2026-2` and standalone `2025`
     * strings substitute, but `12025` or `20254` do not. For *slugs* (caller's
     * context), falls back to appending `-{targetYear}` if no source-year
     * token is found. For *names*, the caller passes the source string back
     * if substitution didn't fire (handled by substituteYearInName).
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
     * Replace the source year inside a human-readable name / short name /
     * description. Unlike slugs, names don't get a `-{year}` suffix if the
     * year token isn't present — the source string is returned unchanged.
     */
    public function substituteYearInName(?string $source, int $sourceYear, int $targetYear): ?string
    {
        if (null === $source || '' === $source) {
            return $source;
        }
        $pattern = sprintf('/(?<!\d)(%d)(?!\d)/', $sourceYear);
        $replaced = preg_replace($pattern, (string) $targetYear, $source);

        return is_string($replaced) ? $replaced : $source;
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
        if ('' === trim($request->targetYearSlug)) {
            throw new \InvalidArgumentException('targetYearSlug must not be empty.');
        }
        // Source-event year must be known up-front: substituteYearInSlug expects
        // a non-null int and the duplicate-slug check below depends on it.
        $sourceYear = $request->sourceYearEvent->getStartYear();
        if (null === $sourceYear) {
            throw new \InvalidArgumentException('Source event missing startDateTime — cannot determine source year.');
        }
        $targetYear = (int) $request->targetYearStartDate->format('Y');
        if ($sourceYear === $targetYear) {
            throw new \InvalidArgumentException('Cannot clone a year onto itself.');
        }
        // Slug uniqueness must ignore soft-deleted rows but the lookup must still
        // catch them — otherwise a second wizard run on a soft-deleted clone
        // would silently overwrite the slug.
        if (null !== $this->findEventBySlugIncludingDeleted($request->targetYearSlug)) {
            throw new DuplicateSlugException($request->targetYearSlug);
        }
        // Sub-event slug pre-check: if any sub-event of the source year has
        // already been cloned with a substituted slug, abort before doClone
        // runs. Without this, a second wizard run with the same source year
        // would silently write duplicate sub-event slugs (Doctrine has no
        // UNIQUE constraint on Event.slug) and break slug-based lookups.
        foreach ($request->sourceYearEvent->getSubEvents() as $subEvent) {
            $sourceSlug = $subEvent->getSlug();
            if (null === $sourceSlug || '' === $sourceSlug) {
                continue;
            }
            $targetSlug = $this->substituteYearInSlug($sourceSlug, $sourceYear, $targetYear);
            if ($targetSlug === $sourceSlug) {
                continue;
            }
            if (null !== $this->findEventBySlugIncludingDeleted($targetSlug)) {
                throw new DuplicateSlugException($targetSlug);
            }
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
            $request->subEventOverrides,
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
        $this->cloneOrganizerParticipants($source, $newYear, $offerCloneMap, $summary);

        return $newYear;
    }

    /**
     * Clone every Participant of category=organizer attached to the source
     * super-event into the new year. Without this step the event detail
     * page renders an empty „organizátor" card — the right-hand box next
     * to the place map — because ParticipantService::getOrganizer relies
     * on a Participant row with category type ORGANIZER.
     *
     * Organizers (typically the operating organization like STUDENTLIFE z.s.)
     * are carried over verbatim — same contact, same priority, formal /
     * informal flag preserved. The new Participant is wired to the cloned
     * RegistrationOffer matched by source ID; if the matching clone is
     * missing for some reason, the source participant is skipped with a
     * warning so the overall clone still succeeds.
     *
     * @param array<int, RegistrationOffer> $offerCloneMap keyed by source offer.id
     * @param array{event_count:int, event_slugs:list<string>, offer_count:int, offer_slugs:list<string>, flag_group_offer_count:int, flag_offer_count:int} $summary mutated in place
     */
    private function cloneOrganizerParticipants(
        Event $source,
        Event $newYear,
        array $offerCloneMap,
        array &$summary,
    ): void {
        if (!array_key_exists('organizer_count', $summary)) {
            $summary['organizer_count'] = 0;
            $summary['organizer_slugs'] = [];
        }
        $sourceOrganizers = $this->em->getRepository(Participant::class)->findBy(['event' => $source]);
        foreach ($sourceOrganizers as $sourceParticipant) {
            if (!$sourceParticipant instanceof Participant) {
                continue;
            }
            $category = $sourceParticipant->getParticipantCategory(true);
            if (null === $category || ParticipantCategory::TYPE_ORGANIZER !== $category->getType()) {
                continue;
            }
            $contact = $sourceParticipant->getContact();
            $sourceOffer = $sourceParticipant->getOffer(true);
            if (null === $contact || null === $sourceOffer) {
                $this->logger->warning(
                    'YearCloneService: organizer participant #{id} skipped — missing contact or offer.',
                    ['id' => $sourceParticipant->getId()],
                );
                continue;
            }
            $clonedOffer = $offerCloneMap[$sourceOffer->getId()] ?? null;
            if (!$clonedOffer instanceof RegistrationOffer) {
                $this->logger->warning(
                    'YearCloneService: organizer participant #{id} skipped — offer #{offerId} not in clone map.',
                    ['id' => $sourceParticipant->getId(), 'offerId' => $sourceOffer->getId()],
                );
                continue;
            }
            try {
                $clone = new Participant($clonedOffer, $contact);
                $clone->setFormal($sourceParticipant->isFormal(false));
                $clone->setPriority($sourceParticipant->getPriority());
                // No variableSymbol carry-over — organization participants
                // don't pay; if a clone ever needs a VS it should be set
                // by the operator. activatedAt + userConfirmedAt start NULL
                // so the row mirrors a fresh registration; admin can confirm.
                $this->em->persist($clone);
                $summary['organizer_count']++;
                $summary['organizer_slugs'][] = $contact->getSlug() ?? ('#'.$sourceParticipant->getId());
                $this->logger->info(
                    'YearCloneService: cloned organizer participant for {slug} into "{event}".',
                    ['slug' => $contact->getSlug(), 'event' => $newYear->getSlug()],
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'YearCloneService: organizer clone failed for participant #{id}: {msg}.',
                    ['id' => $sourceParticipant->getId(), 'msg' => $e->getMessage()],
                );
            }
        }
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
        $clone->setName($this->substituteYearInName($source->getName(), $sourceYear, $targetYear) ?? '');
        $clone->setShortName($this->substituteYearInName($source->getShortName(), $sourceYear, $targetYear));
        $clone->setDescription($this->substituteYearInName($source->getDescription(), $sourceYear, $targetYear));
        $clone->setNote($this->substituteYearInName($source->getNote(), $sourceYear, $targetYear));
        $clone->setInternalNote($this->substituteYearInName($source->getInternalNote(), $sourceYear, $targetYear));
        $clone->setForcedSlug($this->substituteYearInSlug($source->getSlug(), $sourceYear, $targetYear));
        $clone->setPublicOnWeb($source->isPublicOnWeb());
        $clone->setPublicInApp($source->isPublicInApp());

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
        $overrideDeposit = $override === null ? null : $override->depositValue;
        $overrideBaseCapacity = $override === null ? null : $override->baseCapacity;
        $overrideFullCapacity = $override === null ? null : $override->fullCapacity;
        $finalPrice = $overridePrice ?? $sourcePrice;
        $finalDeposit = $overrideDeposit ?? $sourceDeposit;
        $finalBaseCapacity = $overrideBaseCapacity ?? $source->getBaseCapacity();
        $finalFullCapacity = $overrideFullCapacity ?? $source->getFullCapacity();

        $clone = new RegistrationFlagOffer(
            $source->getFlag(),
            new Capacity($finalBaseCapacity, $finalFullCapacity),
            new Price($finalPrice, $finalDeposit),
            $source->getFlagAmountRange(),
            null,
        );
        $clone->setName($this->substituteYearInName($source->getName(), $sourceYear, $targetYear) ?? '');
        $clone->setShortName($this->substituteYearInName($source->getShortName(), $sourceYear, $targetYear));
        $clone->setDescription($this->substituteYearInName($source->getDescription(), $sourceYear, $targetYear));
        $clone->setNote($this->substituteYearInName($source->getNote(), $sourceYear, $targetYear));
        $clone->setInternalNote($this->substituteYearInName($source->getInternalNote(), $sourceYear, $targetYear));
        $clone->setForcedSlug($this->substituteYearInSlug($source->getSlug(), $sourceYear, $targetYear));
        $clone->setPublicOnWeb($source->isPublicOnWeb());
        $clone->setPublicInApp($source->isPublicInApp());
        $clone->setBaseUsage(0);
        $clone->setFullUsage(0);

        return $clone;
    }

    /**
     * @param array<string, Event>                $eventCloneMap  keyed by source slug; mutated in-place
     * @param array<int, SubEventOverride>        $subEventOverrides keyed by source sub-event id
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
        array $subEventOverrides = [],
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
            // Default: uniform timeOffset from super event start. Override:
            // if the operator entered explicit per-turnus dates in Step 2 of
            // the wizard (subEventOverrides keyed by source.id), use those.
            // Operator overrides cover the realistic case where day-of-week
            // and weekend gap don't line up between source and target years.
            $childOverride = ($childId = $sourceChild->getId()) !== null
                ? ($subEventOverrides[$childId] ?? null) : null;
            if ($childOverride?->startDateTime !== null) {
                $newChildStart = $childOverride->startDateTime;
            } else {
                $newChildStart = (new \DateTimeImmutable())->setTimestamp($childStart->getTimestamp() + $timeOffsetSeconds);
            }
            if ($childOverride?->endDateTime !== null) {
                $newChildEnd = $childOverride->endDateTime;
            } else {
                $newChildEnd = (new \DateTimeImmutable())->setTimestamp($childEnd->getTimestamp() + $timeOffsetSeconds);
            }

            $newChild = $this->cloneEventShallow(
                $sourceChild,
                $this->substituteYearInSlug($sourceChild->getSlug(), $sourceYear, $targetYear),
                $this->substituteYearInName($sourceChild->getName(), $sourceYear, $targetYear) ?? '',
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
                $subEventOverrides,
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
        $clone->setName($this->substituteYearInName($source->getName(), $sourceYear, $targetYear) ?? '');
        $clone->setForcedSlug($this->substituteYearInSlug($source->getSlug(), $sourceYear, $targetYear));
        $clone->setShortName($this->substituteYearInName($source->getShortName(), $sourceYear, $targetYear));
        $clone->setDescription($this->substituteYearInName($source->getDescription(), $sourceYear, $targetYear));
        $clone->setNote($this->substituteYearInName($source->getNote(), $sourceYear, $targetYear));
        $clone->setInternalNote($this->substituteYearInName($source->getInternalNote(), $sourceYear, $targetYear));
        $clone->setEvent($newEvent);
        $clone->setParticipantCategory($source->getParticipantCategory());
        $clone->setPriority($source->getPriority());
        $clone->setRelative($source->isRelative());
        $clone->setSurrogate($source->isSurrogate());
        $clone->setSuperEventRequired($source->isSuperEventRequired());
        $clone->setRequiredRegRange(null); // remap in Pass 7b
        $clone->setPublicOnWeb($source->isPublicOnWeb());
        $clone->setPublicInApp($source->isPublicInApp());
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
        // recursive=false → keep the price LAYERED across the offer tree
        // (super offer holds the price, sub-turnus offers are 0/NULL).
        // Passing recursive=true here previously caused a doubling bug:
        // 1. turnus 2026 inherited 5390 from required super, the wizard
        // saved 5390 onto its own row, and runtime then summed 5390 + 5390
        // = 10780 Kč when rendering the participant's price.
        $sourcePrice = $source->getPrice(null, false);
        $sourceDeposit = $source->getDepositValue(null, false);
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
        // Derive years from the source and target start dates so all
        // year-bearing text (shortName, description, note, internal note)
        // gets the same substitution as the slug/name.
        $sourceYear = $source->getStartYear() ?? (int) $newStart->format('Y');
        $targetYear = (int) $newStart->format('Y');

        $clone = new Event();
        $clone->setName($newName);
        $clone->setForcedSlug($newSlug);
        $clone->setShortName($this->substituteYearInName($source->getShortName(), $sourceYear, $targetYear));
        $clone->setDescription($this->substituteYearInName($source->getDescription(), $sourceYear, $targetYear));
        $clone->setNote($this->substituteYearInName($source->getNote(), $sourceYear, $targetYear));
        $clone->setInternalNote($this->substituteYearInName($source->getInternalNote(), $sourceYear, $targetYear));
        $clone->setStartDateTime(\DateTime::createFromInterface($newStart));
        $clone->setEndDateTime(\DateTime::createFromInterface($newEnd));
        $clone->setColor($source->getColor());
        $clone->setPlace($source->getPlace(false));
        $clone->setOrganizer($source->getOrganizer(false));
        // Bank account is what summary mails and QR payments read from. The
        // 2026 launch found the cloned ročník with no account → e-mails fell
        // back to „dle webových stránek" and QR generation skipped entirely.
        $sourceBank = $source->getBankAccount(false);
        if (null !== $sourceBank && !empty($sourceBank->getFull())) {
            $clone->setBankAccountPrefix($sourceBank->getPrefix());
            $clone->setBankAccountNumber($sourceBank->getAccountNumber());
            $clone->setBankAccountBank($sourceBank->getBankCode());
        }
        $clone->setCategory($source->getCategory());
        $clone->setGroup($source->getGroup());
        $clone->setSuperEvent($newSuperEvent);
        // Inherit visibility flags from source — admin who runs the wizard
        // explicitly chose to clone a year, so the new ročník should be
        // visible on the same surfaces as the source. Admin can flip flags
        // later via the event edit screen.
        $clone->setPublicOnWeb($source->isPublicOnWeb());
        $clone->setPublicInApp($source->isPublicInApp());

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
