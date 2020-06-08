<?php

namespace OswisOrg\OswisCalendarBundle\Exception;

use Exception;

class FlagCapacityExceededException extends Exception
{
    public function __construct(?string $flagName = null, ?string $message = null)
    {
        parent::__construct($message ?? "Kapacita příznaku $flagName byla překročena.");
    }
}
