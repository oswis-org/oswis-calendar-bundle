<?php

namespace OswisOrg\OswisCalendarBundle\Exception;

use Exception;

class FlagOutOfRangeException extends Exception
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Příznaky u přihlášky jsou mimo rozsah.');
    }
}
