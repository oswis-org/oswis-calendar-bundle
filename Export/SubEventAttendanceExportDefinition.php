<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Export;

use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractPerson;
use OswisOrg\OswisCalendarBundle\Entity\Participant\SubEventAttendance;
use OswisOrg\OswisCoreBundle\Export\ExportColumn;
use OswisOrg\OswisCoreBundle\Export\ExportDefinitionInterface;

/**
 * "Seznam přihlášených" na konkrétní programovou aktivitu (kdo je zapsán / zaplatil) — tabulkový
 * výstup pro vedoucího aktivity / kiosek, renderovaný přes {@see \OswisOrg\OswisCoreBundle\Export\ExportManager}
 * do CSV i PDF. Kolekci dodává volající (web admin / API) z {@see \OswisOrg\OswisCalendarBundle\Repository\Participant\SubEventAttendanceRepository::getActiveByEvent()}.
 * Liší se od zápisového archu ({@see \OswisOrg\OswisCalendarBundle\Service\Program\ProgramOutputService::signupSheetPdf()}),
 * což je prázdný papír k ručnímu zápisu. STOPA 1.3 Fáze 6.
 */
final class SubEventAttendanceExportDefinition implements ExportDefinitionInterface
{
    /** Stejný strop jako u účastnického exportu — volající načte MAX+1 a odmítne nezúžený rozsah. */
    public const int MAX_EXPORT_ROWS = 1000;

    public function getKey(): string
    {
        return 'subevent_attendees';
    }

    public function getResourceClass(): string
    {
        return SubEventAttendance::class;
    }

    public function getTitle(): string
    {
        return 'Seznam přihlášených';
    }

    public function getColumns(): array
    {
        return [
            new ExportColumn('fullName', 'Jméno', static fn (object $a): mixed => self::person($a)?->getFullName()),
            new ExportColumn('phone', 'Telefon', static fn (object $a): mixed => $a instanceof SubEventAttendance ? $a->getParticipant()?->getContactForRead()?->getPhone() : null),
            new ExportColumn('paid', 'Zaplaceno', static fn (object $a): mixed => $a instanceof SubEventAttendance ? (true === $a->isPaid() ? 'Ano' : 'Ne') : null),
            new ExportColumn('registeredAt', 'Datum zápisu', static fn (object $a): mixed => $a instanceof SubEventAttendance ? $a->getRegisteredAt() : null, true, ExportColumn::TYPE_DATETIME),
            new ExportColumn('status', 'Stav', static fn (object $a): mixed => $a instanceof SubEventAttendance ? $a->getStatus() : null, false),
        ];
    }

    private static function person(object $attendance): ?AbstractPerson
    {
        if (!$attendance instanceof SubEventAttendance) {
            return null;
        }
        $contact = $attendance->getParticipant()?->getContactForRead();

        return $contact instanceof AbstractPerson ? $contact : null;
    }
}
