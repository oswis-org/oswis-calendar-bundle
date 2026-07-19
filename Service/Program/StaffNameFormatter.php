<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Program;

use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractPerson;

/**
 * Jednotné formátování zobrazovaného jména člena týmu pro program a rozpis služeb.
 *
 * Pravidlo (provozní realita — v dokumentu „SLUŽBY" se lidé vedou PŘEZDÍVKAMI, např. „GABČA",
 * a dva Kubové se odliší iniciálou příjmení „KUBA V." vs „KUBA U."):
 *   1. přezdívka, pokud je vyplněná;
 *   2. jinak křestní jméno + iniciála příjmení („Gabriela N." → „Gabriela N.");
 *   3. u ne-osobního kontaktu (organizace apod.) jeho složené jméno, případně řaditelné jméno.
 *
 * Proč samostatná třída: totéž pravidlo potřebuje jak Twig na webu/PDF
 * ({@see \OswisOrg\OswisCalendarBundle\Twig\Extension\ProgramExtension::staffName}), tak API
 * serializace roštu služeb ({@see \OswisOrg\OswisCalendarBundle\Entity\Staff\StaffAssignment::getStaffName}).
 * Entita nemůže injektovat Twig rozšíření, proto je jádro vytažené sem jako čistá statická funkce,
 * ať se pravidlo nepíše dvakrát a nemůže se rozejít. Viz [[feedback_quality_code_documentation]].
 */
final class StaffNameFormatter
{
    public static function format(?AbstractContact $contact): string
    {
        if ($contact instanceof AbstractPerson) {
            $nickname = trim((string) $contact->getNickname());
            if ('' !== $nickname) {
                return $nickname;
            }
            $given = trim((string) $contact->getGivenName());
            if ('' !== $given) {
                $family = trim((string) $contact->getFamilyName());
                $initial = '' !== $family ? ' '.mb_strtoupper(mb_substr($family, 0, 1)).'.' : '';

                return $given.$initial;
            }
        }
        if ($contact instanceof AbstractContact) {
            $name = trim((string) $contact->getName());

            return '' !== $name ? $name : trim((string) $contact->getSortableName());
        }

        return '';
    }
}
