<?php

namespace Zakjakub\OswisCalendarBundle\Exceptions;

use Exception;

class EventCapacityExceededException extends Exception
{
    public function __construct()
    {
        parent::__construct('Kapacita akce byla překročena.');
    }
}