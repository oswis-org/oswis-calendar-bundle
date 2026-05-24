<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

/**
 * Per-row admin override for a single RegistrationFlagOffer during year clone.
 *
 * Spec: docs/superpowers/specs/2026-05-21-S5-year-clone-wizard-design.md
 */
final readonly class FlagOverride
{
    public function __construct(
        public ?int $price = null,
        public ?int $depositValue = null,
        public ?int $baseCapacity = null,
        public ?int $fullCapacity = null,
    ) {
    }
}
