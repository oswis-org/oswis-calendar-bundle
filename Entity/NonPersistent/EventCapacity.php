<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

class EventCapacity
{
    public ?int $capacity = null;

    public ?int $capacityOverflowLimit = null;

    public function __construct(?int $capacity = null, ?int $capacityOverflowLimit = null)
    {
        $this->capacity = $capacity;
        $this->capacityOverflowLimit = $capacityOverflowLimit;
    }
}
