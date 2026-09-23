<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;

/**
 * Ruční zpráva přihláškám — to, co autor napsal, bez ohledu na počet příjemců (spec 2026-09-13 §5:
 * zpráva jednomu = zpráva pro N). Předmět je Twig; obsah je buď text ve formátu těla mailu
 * (proměnné, bloky, tlačítka — {@see \OswisOrg\OswisCoreBundle\Mail\Rendering\MailRenderer}), nebo
 * uložená šablona (kampaň) podle slugu.
 *
 * `document` = obsah je CELÝ Twig dokument (`{% extends %}` + bloky) — neuložená kampaň z editoru
 * šablony. Vykreslí se tak, jak ji při odeslání načte DatabaseLoader, bez balení do obálky. Jen pro
 * náhled; odesílat se dá dál jen tělo zprávy, nebo uložená kampaň podle slugu.
 */
final readonly class ParticipantManualMail
{
    public ?string $templateSlug;

    public function __construct(
        public string $subject,
        public string $body = '',
        ?string $templateSlug = null,
        public ?string $adminName = null,
        public bool $document = false,
    ) {
        $this->templateSlug = null !== $templateSlug && '' !== trim($templateSlug) ? trim($templateSlug) : null;
    }

    public static function fromBulk(ParticipantMailBulk $bulk): self
    {
        return new self($bulk->getSubject(), $bulk->getBodyHtml(), $bulk->getTemplateSlug(), $bulk->getAdminName());
    }

    public function usesTemplate(): bool
    {
        return null !== $this->templateSlug;
    }
}
