<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Export;

use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractPerson;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCoreBundle\Export\ExportColumn;
use OswisOrg\OswisCoreBundle\Export\ExportDefinitionInterface;

final class ParticipantExportDefinition implements ExportDefinitionInterface
{
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
            new ExportColumn('id', 'ID', static fn (object $p): mixed => $p instanceof Participant ? $p->getId() : null),
            new ExportColumn('familyName', 'Příjmení', static fn (object $p): mixed => self::person($p)?->getFamilyName()),
            new ExportColumn('givenName', 'Křestní jméno', static fn (object $p): mixed => self::person($p)?->getGivenName()),
            new ExportColumn('fullName', 'Celé jméno', static fn (object $p): mixed => self::person($p)?->getFullName()),
            new ExportColumn('variableSymbol', 'Variabilní symbol', static fn (object $p): mixed => $p instanceof Participant ? $p->getVariableSymbol() : null),
            new ExportColumn('event', 'Akce', static fn (object $p): mixed => $p instanceof Participant ? ($p->getEvent()?->getShortName() ?? $p->getEvent()?->getName()) : null),
            new ExportColumn('category', 'Kategorie', static fn (object $p): mixed => $p instanceof Participant ? $p->getParticipantCategory()?->getName() : null),
            new ExportColumn('email', 'E-mail', static fn (object $p): mixed => $p instanceof Participant ? $p->getContactForRead()?->getEmail() : null),
            new ExportColumn('phone', 'Telefon', static fn (object $p): mixed => $p instanceof Participant ? $p->getContactForRead()?->getPhone() : null),
            new ExportColumn('tShirt', 'Velikost trička', static fn (object $p): mixed => $p instanceof Participant ? $p->getTShirt() : null),
            new ExportColumn('createdAt', 'Datum přihlášky', static fn (object $p): mixed => $p instanceof Participant ? $p->getCreatedAt() : null, true, ExportColumn::TYPE_DATETIME),
            new ExportColumn('activated', 'Aktivováno', static fn (object $p): mixed => $p instanceof Participant ? ($p->getActivated()?->format('Y-m-d') ?? 'Ne') : null),
            new ExportColumn('price', 'Celková cena', static fn (object $p): mixed => $p instanceof Participant ? $p->getPrice() : null, true, ExportColumn::TYPE_NUMBER),
            new ExportColumn('paidPrice', 'Zaplaceno [Kč]', static fn (object $p): mixed => $p instanceof Participant ? $p->getPaidPrice() : null, true, ExportColumn::TYPE_NUMBER),
            new ExportColumn('remainingPrice', 'Zbývá [Kč]', static fn (object $p): mixed => $p instanceof Participant ? $p->getRemainingPrice() : null, true, ExportColumn::TYPE_NUMBER),
            new ExportColumn('paidPercentage', 'Zaplaceno [%]', static fn (object $p): mixed => $p instanceof Participant ? str_replace('.', ',', (string) ($p->getPaidPricePercentage() * 100)) : null),
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
}
