<?php

namespace Zakjakub\OswisCalendarBundle\Exception;

use Exception;

class OswisEventParticipantNotFoundException extends Exception
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Účastník akce nebyl nalezen.');
    }
}
