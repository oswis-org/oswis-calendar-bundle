<?php

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

class EventCapacity
{
    public ?int $capacity = null;

    public ?int $maxCapacity = null;

    public function __construct(?int $capacity = null, ?int $maxCapacity = null)
    {
        $this->capacity = $capacity;
        $this->maxCapacity = $maxCapacity;
    }
}
