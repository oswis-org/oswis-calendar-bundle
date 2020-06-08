<?php

namespace OswisOrg\OswisCalendarBundle\Exception;

use Exception;

class ParticipantIncompleteException extends Exception
{
    public function __construct(?string $missing = null, ?string $message = null)
    {
        parent::__construct($message ?? "Přihláška není kompletní. $missing");
    }
}
