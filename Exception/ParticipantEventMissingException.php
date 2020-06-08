<?php

namespace OswisOrg\OswisCalendarBundle\Exception;

class ParticipantEventMissingException extends ParticipantIncompleteException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(null, $message ?? "V přihlášce chybí událost.");
    }
}
