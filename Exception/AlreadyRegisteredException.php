<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Exception;

use OswisOrg\OswisCoreBundle\Exceptions\OswisException;

/**
 * Vyhozena, když se člověk přihlašuje na turnus, na který už živou přihlášku má.
 *
 * Do 24. 8. 2026 tenhle případ nikdo neodlišoval: jedinou pojistkou proti opakovanému
 * odeslání bylo šedesátisekundové okno proti dvojkliku na iOS. Kdo se přihlásil znovu
 * později, spadl do větve „vrací se k nám z dřívějška" a dostal magic-link e-mail
 * „Pokračování v přihlášce" — přestože žádné pokračování nepotřeboval, přihlášku měl
 * hotovou. Tým ty maily viděl chodit do archivu a nechápal proč.
 *
 * Kontrolery ji zachytávají zvlášť a vykreslují informativní odpověď, ne chybu: pro
 * člověka je to dobrá zpráva — přihláška existuje. Pokud navíc nemá aktivovaný účet,
 * pošle se mu znovu ověřovací e-mail, protože přesně to je důvod, proč to zkouší znovu.
 */
final class AlreadyRegisteredException extends OswisException
{
    public function __construct(
        string $message,
        private readonly ?int $participantId = null,
        private readonly bool $potrebujeOvereni = false,
    ) {
        parent::__construct($message);
    }

    public function getParticipantId(): ?int
    {
        return $this->participantId;
    }

    /** Účet u té přihlášky ještě není aktivovaný — nabídnout znovuposlání ověřovacího e-mailu. */
    public function potrebujeOvereni(): bool
    {
        return $this->potrebujeOvereni;
    }
}
