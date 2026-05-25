<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

use DateTimeImmutable;

/**
 * Per-sub-event override for a single child Event during year clone — turnusy
 * + příjezdové dny + sub-activities. Without explicit per-event dates the
 * wizard inherits a uniform time offset from the super event start, which
 * misaligns day-of-week and weekend gaps (e.g. 2025 Mon→Fri shifted to 2026
 * Tue→Sat — a real bug spotted at 2026 launch).
 */
final readonly class SubEventOverride
{
    public function __construct(
        public ?DateTimeImmutable $startDateTime = null,
        public ?DateTimeImmutable $endDateTime = null,
    ) {
    }
}
