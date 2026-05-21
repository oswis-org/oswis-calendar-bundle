<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Event;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\YearCloneRequest;
use OswisOrg\OswisCalendarBundle\Exception\DuplicateSlugException;
use OswisOrg\OswisCalendarBundle\Exception\YearCloneDryRunCompleteException;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use Psr\Log\LoggerInterface;

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

    public function cloneYear(YearCloneRequest $request): Event
    {
        $this->validate($request);

        return $this->em->wrapInTransaction(function () use ($request): Event {
            $cloned = $this->doClone($request);
            if ($request->dryRun) {
                throw new YearCloneDryRunCompleteException(
                    $this->buildSummary($cloned),
                );
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
     * Phase 1: clone the YEAR_OF_EVENT and its BATCH_OF_EVENT children.
     * Sub-activities and registration offers added in later tasks.
     *
     * @return Event the newly persisted year-level event
     */
    private function doClone(YearCloneRequest $request): Event
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
        $this->logger->info('YearCloneService: cloned year "{slug}".', ['slug' => $newYear->getSlug()]);

        $offset = $targetStart->getTimestamp() - $sourceStart->getTimestamp();

        foreach ($source->getSubEvents() as $sourceChild) {
            if ($sourceChild->getCategory()?->getType() !== EventCategory::BATCH_OF_EVENT) {
                continue; // sub-activities handled in Task 4
            }
            $childStart = $sourceChild->getStartDateTime();
            $childEnd = $sourceChild->getEndDateTime();
            if (null === $childStart || null === $childEnd) {
                $this->logger->warning('Skipping batch with missing dates: {slug}', ['slug' => $sourceChild->getSlug()]);
                continue;
            }
            $newChildStart = (new \DateTimeImmutable())->setTimestamp($childStart->getTimestamp() + $offset);
            $newChildEnd = (new \DateTimeImmutable())->setTimestamp($childEnd->getTimestamp() + $offset);

            $newChild = $this->cloneEventShallow(
                $sourceChild,
                $this->substituteYearInSlug($sourceChild->getSlug() ?? '', $sourceYear, $targetYear),
                $sourceChild->getName() ?? '',
                $newChildStart,
                $newChildEnd,
                $newYear,
            );
            $this->em->persist($newChild);
        }

        return $newYear;
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
        $clone->setSlug($newSlug);
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

        return $clone;
    }

    /**
     * @return array<string, int|array<int, string>>
     */
    private function buildSummary(Event $cloned): array
    {
        // Stubbed in this task — implemented in Task 9 (dry-run controller integration).
        return ['cloned_event_id' => $cloned->getId() ?? 0];
    }
}
