<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Exception;

use OswisOrg\OswisCoreBundle\Exceptions\OswisException;

class ParticipantPaymentException extends OswisException
{
    protected ?string $shortMessage = null;

    public function __construct(?string $message = null, ?string $shortMessage = null)
    {
        parent::__construct($message ?? "Vytvoření platby se nezdařilo");
        $this->shortMessage = $shortMessage;
    }

    public function getShortMessage(): ?string
    {
        return $this->shortMessage;
    }
}
