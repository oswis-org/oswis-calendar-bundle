<?php

namespace OswisOrg\OswisCalendarBundle\Exception;

use Exception;

class ParticipantNotFoundException extends Exception
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Účastník akce nebyl nalezen.');
    }
}
