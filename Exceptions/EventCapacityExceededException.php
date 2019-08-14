<?php

namespace Zakjakub\OswisCalendarBundle\Exceptions;

use Exception;

class EventCapacityExceededException extends Exception
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Kapacita akce byla překročena.');
    }
}
