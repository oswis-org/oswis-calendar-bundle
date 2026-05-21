<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Exception;

use Exception;

/**
 * Sentinel exception thrown at the end of a dry-run clone transaction
 * to force rollback. The wizard controller catches this specific class
 * and extracts the summary payload to render the preview banner.
 *
 * Spec: docs/superpowers/specs/2026-05-21-S5-year-clone-wizard-design.md S5 dry-run
 */
final class YearCloneDryRunCompleteException extends Exception
{
    /**
     * @param array<string, int|array<string|int, mixed>> $summary entity counts + cloned slug list
     */
    public function __construct(public readonly array $summary)
    {
        parent::__construct('Year clone dry-run complete; rolling back.');
    }
}
