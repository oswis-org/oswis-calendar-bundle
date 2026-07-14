<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Accommodation;

/**
 * Upozornění constraint enginu ubytování. MĚKKÉ — jen varuje, NEblokuje (user: „upozorňovat, netvrdě
 * zakazovat"; overbooking je záměr). Obsluha může přiřadit i přes varování (s vědomím).
 */
final readonly class AccommodationWarning
{
    public const string CODE_OVER_CAPACITY = 'over_capacity';
    public const string CODE_UNAVAILABLE = 'unavailable';
    public const string CODE_GROUP_SPLIT = 'group_split';
    public const string CODE_BED_MISMATCH = 'bed_mismatch';
    public const string CODE_FLAG_TYPE_MISMATCH = 'flag_type_mismatch';
    public const string CODE_PARTIAL_STAY = 'partial_stay';

    public function __construct(
        public string $code,
        public string $message,
    ) {
    }
}
