<?php

namespace OswisOrg\OswisCalendarBundle\Exception;

class ParticipantRangeMissingException extends ParticipantIncompleteException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(null, $message ?? "V přihlášce chybí spojení na rozsah přihlášek.");
    }
}
