<?php

namespace OswisOrg\OswisCalendarBundle\Exception;

class ParticipantContactMissingException extends ParticipantIncompleteException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(null, $message ?? "V přihlášce chybí kontakt (osoba nebo organizace).");
    }
}
