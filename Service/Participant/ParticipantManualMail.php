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
    /** Soubor odejde jako příloha mailu. */
    public const string PRILOHA = 'priloha';
    /** Soubor odejde jako odkaz ke stažení (seznam „Ke stažení" pod textem) — dávka 3.5. */
    public const string ODKAZ = 'odkaz';

    public ?string $templateSlug;

    /** @var list<array{id: int, mode: string}> přílohy ke zprávě (ID z úložiště příloh a způsob odeslání) */
    public array $attachments;

    public function __construct(
        public string $subject,
        public string $body = '',
        ?string $templateSlug = null,
        public ?string $adminName = null,
        public bool $document = false,
        /** Rodič z pole „Vychází z" — doplní se do zdroje stejně jako při odeslání (jen s `document`). */
        public ?string $parent = null,
        array $attachments = [],
    ) {
        $this->templateSlug = null !== $templateSlug && '' !== trim($templateSlug) ? trim($templateSlug) : null;
        $this->attachments = self::normalizovatPrilohy($attachments);
    }

    public static function fromBulk(ParticipantMailBulk $bulk): self
    {
        return new self($bulk->getSubject(), $bulk->getBodyHtml(), $bulk->getTemplateSlug(), $bulk->getAdminName(), attachments: $bulk->getAttachments());
    }

    /** Táž zpráva s jinými přílohami. */
    public function sPrilohami(array $attachments): self
    {
        return new self($this->subject, $this->body, $this->templateSlug, $this->adminName, $this->document, $this->parent, $attachments);
    }

    /**
     * Přílohy z formuláře (skryté pole s JSON `[{id, mode}]`). Neplatné položky a duplicity zahodí; neznámý způsob = příloha.
     *
     * @return list<array{id: int, mode: string}>
     */
    public static function prilohyZJson(?string $json): array
    {
        $data = null !== $json && '' !== trim($json) ? json_decode($json, true) : [];

        return self::normalizovatPrilohy(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $attachments
     *
     * @return list<array{id: int, mode: string}>
     */
    private static function normalizovatPrilohy(array $attachments): array
    {
        $vysledek = [];
        foreach ($attachments as $priloha) {
            $id = is_array($priloha) && is_numeric($priloha['id'] ?? null) ? (int) $priloha['id'] : 0;
            if ($id <= 0 || isset($vysledek[$id])) {
                continue;
            }
            $vysledek[$id] = ['id' => $id, 'mode' => self::ODKAZ === ($priloha['mode'] ?? null) ? self::ODKAZ : self::PRILOHA];
        }

        return array_values($vysledek);
    }

    /** Zpráva jednomu příjemci: odkaz se nenabízí — vše jako příloha (rozhodnutí uživatele 7. 10. 2026). */
    public function vseJakoPriloha(): self
    {
        return $this->sPrilohami(array_map(static fn (array $p): array => ['id' => $p['id'], 'mode' => self::PRILOHA], $this->attachments));
    }

    public function usesTemplate(): bool
    {
        return null !== $this->templateSlug;
    }
}
