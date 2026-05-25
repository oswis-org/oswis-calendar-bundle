<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

use DateTimeImmutable;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;

/**
 * Non-persistent DTO carrying a year-clone request for YearCloneService.
 *
 * Spec: docs/superpowers/specs/2026-05-21-S5-year-clone-wizard-design.md
 */
final readonly class YearCloneRequest
{
    /**
     * @param array<int, OfferOverride>     $offerOverrides keyed by source RegistrationOffer.id
     * @param array<int, FlagOverride>      $flagOverrides  keyed by source RegistrationFlagOffer.id
     * @param array<int, SubEventOverride>  $subEventOverrides keyed by source sub-event.id — explicit per-turnus dates so the operator picks the real start/end (Sat–Tue) instead of inheriting a uniform offset from the super event start
     */
    public function __construct(
        public Event $sourceYearEvent,
        public DateTimeImmutable $targetYearStartDate,
        public DateTimeImmutable $targetYearEndDate,
        public string $targetYearName,
        public string $targetYearSlug,
        public bool $cloneSubActivities = true,
        public array $offerOverrides = [],
        public array $flagOverrides = [],
        public array $subEventOverrides = [],
        public bool $dryRun = false,
    ) {
    }
}
