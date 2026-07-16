<?php

namespace OswisOrg\OswisCalendarBundle\Exception;

use Exception;

class EventCapacityExceededException extends Exception
{
    public function __construct(?string $eventName = null, ?string $message = null)
    {
        // Prázdné jméno akce nesmí vyrobit „Kapacita akce  byla překročena." (dvojitá mezera,
        // reálně v prod logu 3.7. i 14.7.2026) — bez jména srozumitelná obecná hláška.
        $eventName = null !== $eventName ? trim($eventName) : '';
        parent::__construct($message ?? ('' !== $eventName
            ? "Kapacita akce $eventName byla překročena."
            : 'Kapacita akce byla překročena.'));
    }
}
