<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Export;

use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractPerson;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCoreBundle\Export\ExportColumn;
use OswisOrg\OswisCoreBundle\Export\ExportDefinitionInterface;

final class ParticipantExportDefinition implements ExportDefinitionInterface
{
    /**
     * Hard ceiling on rows in a single participant export. A full export hydrates the whole heavy
     * Participant graph (eager associations) per row; an unscoped dump of thousands of rows
     * exhausts memory (OOM in Doctrine's deep clone during hydration). Both export entry points
     * (web admin list export, API export for the Ionic admin) load at most MAX_EXPORT_ROWS+1 via a
     * true SQL LIMIT and refuse — with a clear error, never a silent truncation — when the scope
     * exceeds it, telling the caller to narrow the scope (pick an event/year).
     */
    public const int MAX_EXPORT_ROWS = 1000;

    public function getKey(): string
    {
        return 'participants';
    }

    public function getResourceClass(): string
    {
        return Participant::class;
    }

    public function getTitle(): string
    {
        return 'Přehled přihlášek';
    }

    public function getColumns(): array
    {
        return [
            new ExportColumn('id', 'ID', static fn (object $p): mixed => $p instanceof Participant ? $p->getId() : null, true, ExportColumn::TYPE_TEXT, ExportColumn::ALIGN_RIGHT),
            new ExportColumn('familyName', 'Příjmení', static fn (object $p): mixed => self::person($p)?->getFamilyName()),
            new ExportColumn('givenName', 'Křestní jméno', static fn (object $p): mixed => self::person($p)?->getGivenName()),
            new ExportColumn('fullName', 'Celé jméno', static fn (object $p): mixed => self::person($p)?->getFullName()),
            new ExportColumn('variableSymbol', 'Variabilní symbol', static fn (object $p): mixed => $p instanceof Participant ? $p->getVariableSymbol() : null),
            new ExportColumn('event', 'Akce', static fn (object $p): mixed => $p instanceof Participant ? ($p->getEvent()?->getShortName() ?? $p->getEvent()?->getName()) : null),
            new ExportColumn('category', 'Kategorie', static fn (object $p): mixed => $p instanceof Participant ? $p->getParticipantCategory()?->getName() : null),
            new ExportColumn('email', 'E-mail', static fn (object $p): mixed => $p instanceof Participant ? $p->getContactForRead()?->getEmail() : null, true, ExportColumn::TYPE_EMAIL),
            new ExportColumn('phone', 'Telefon', static fn (object $p): mixed => $p instanceof Participant ? $p->getContactForRead()?->getPhone() : null, true, ExportColumn::TYPE_PHONE),
            new ExportColumn('tShirt', 'Velikost trička', static fn (object $p): mixed => $p instanceof Participant ? $p->getTShirt() : null),
            // Flag-derived provozní sloupce (nejsou ve výchozím exportu — slouží provozním listům).
            new ExportColumn('accommodation', 'Ubytování', static fn (object $p): mixed => self::flagNames($p, RegistrationFlagCategory::TYPE_ACCOMMODATION_TYPE), false),
            new ExportColumn('food', 'Strava', static fn (object $p): mixed => self::flagNames($p, RegistrationFlagCategory::TYPE_FOOD), false),
            new ExportColumn('createdAt', 'Datum přihlášky', static fn (object $p): mixed => $p instanceof Participant ? $p->getCreatedAt() : null, true, ExportColumn::TYPE_DATETIME),
            // Čte se `userConfirmedAt` = kdy účastník potvrdil přihlášku klikem v e-mailu.
            // Dřív se sahalo na `Participant::getActivated()`, jenže to pole se NIKDY neplní
            // (na klonu 0 z 244 letošních přihlášek, potvrzených přitom 236) → ve sloupci bylo
            // u všech „Ne". Táž záměna os jako u filtru „Neaktivované" (opraveno 2026-07-30).
            new ExportColumn('activated', 'Potvrzeno', static fn (object $p): mixed => $p instanceof Participant ? ($p->getUserConfirmedAt()?->format('j. n. Y') ?? 'Ne') : null),
            new ExportColumn('price', 'Celková cena', static fn (object $p): mixed => $p instanceof Participant ? $p->getPrice() : null, true, ExportColumn::TYPE_NUMBER),
            new ExportColumn('paidPrice', 'Zaplaceno [Kč]', static fn (object $p): mixed => $p instanceof Participant ? $p->getPaidPrice() : null, true, ExportColumn::TYPE_NUMBER),
            new ExportColumn('remainingPrice', 'Zbývá [Kč]', static fn (object $p): mixed => $p instanceof Participant ? $p->getRemainingPrice() : null, true, ExportColumn::TYPE_NUMBER),
            // Vpravo jako číslo, ale ZÁMĚRNĚ ne TYPE_NUMBER — ten se v PDF sčítá do řádku
            // součtů a součet procent nedává smysl.
            new ExportColumn('paidPercentage', 'Zaplaceno [%]', static fn (object $p): mixed => $p instanceof Participant ? str_replace('.', ',', (string) round($p->getPaidPricePercentage() * 100)) : null, true, ExportColumn::TYPE_TEXT, ExportColumn::ALIGN_RIGHT),
            new ExportColumn('deletedAt', 'Smazáno', static fn (object $p): mixed => $p instanceof Participant ? ($p->getDeletedAt()?->format('Y-m-d') ?? '') : null),
        ];
    }

    private static function person(object $participant): ?AbstractPerson
    {
        if (!$participant instanceof Participant) {
            return null;
        }
        $contact = $participant->getContactForRead();

        return $contact instanceof AbstractPerson ? $contact : null;
    }

    /**
     * Čárkou oddělené názvy aktivních příznaků dané kategorie/typu (např. ubytování, strava).
     */
    private static function flagNames(object $participant, string $flagType): ?string
    {
        if (!$participant instanceof Participant) {
            return null;
        }
        $names = [];
        // ParticipantFlag connection (ne master RegistrationFlag) — drží per-flag textValue,
        // takže „Jiné" dietní omezení vyexportuje i s upřesněním (kuchyň potřebuje detail).
        foreach ($participant->getParticipantFlags(null, $flagType, true) as $participantFlag) {
            $name = $participantFlag->getFlag()?->getName();
            if (!is_string($name) || '' === $name) {
                continue;
            }
            $textValue = $participantFlag->getTextValue();
            $names[] = (is_string($textValue) && '' !== trim($textValue))
                ? $name.' ('.trim($textValue).')'
                : $name;
        }

        return implode(', ', $names);
    }

    /**
     * Pojmenované sloupcové presety = provozní listy (jedno-klik export předvolené podmnožiny
     * sloupců ve vhodném formátu). Klíče sloupců odpovídají {@see getColumns()}.
     *
     * @return list<array{key: string, label: string, columns: list<string>, format: string}>
     */
    public function getPresets(): array
    {
        return [
            [
                'key'     => 'contact',
                'label'   => 'Kontaktní list',
                'columns' => ['familyName', 'givenName', 'email', 'phone', 'event', 'category'],
                'format'  => 'pdf',
            ],
            [
                'key'     => 'payment',
                'label'   => 'Platební list',
                'columns' => ['familyName', 'givenName', 'variableSymbol', 'price', 'paidPrice', 'remainingPrice', 'paidPercentage'],
                'format'  => 'pdf',
            ],
            [
                'key'     => 'tshirt',
                'label'   => 'Výdej triček',
                'columns' => ['familyName', 'givenName', 'tShirt', 'event', 'paidPrice'],
                'format'  => 'pdf',
            ],
            [
                'key'     => 'accommodation',
                'label'   => 'Ubytovací / stravovací',
                'columns' => ['familyName', 'givenName', 'accommodation', 'food', 'event'],
                'format'  => 'pdf',
            ],
        ];
    }
}
