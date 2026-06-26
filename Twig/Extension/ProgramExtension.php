<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Twig\Extension;

use DateTimeInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractPerson;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig helpers for the program-module outputs (STOPA 1.3): displayable staff name,
 * morning/afternoon/evening time block label, Roman numerals for activity series.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md sekce 2.4.
 */
final class ProgramExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('staff_name', $this->staffName(...)),
            new TwigFilter('time_block', $this->timeBlock(...)),
            new TwigFilter('roman', $this->roman(...)),
        ];
    }

    /**
     * Display name of a team member: nickname if set, else "Křestní P." (given name + family
     * initial), else the contact's composed name. Accepts a Participant or a contact.
     */
    public function staffName(mixed $subject): string
    {
        $contact = $this->resolveContact($subject);
        if ($contact instanceof AbstractPerson) {
            $nickname = trim((string) $contact->getNickname());
            if ('' !== $nickname) {
                return $nickname;
            }
            $given = trim((string) $contact->getGivenName());
            if ('' !== $given) {
                $family = trim((string) $contact->getFamilyName());
                $initial = '' !== $family ? ' ' . mb_strtoupper(mb_substr($family, 0, 1)) . '.' : '';

                return $given . $initial;
            }
        }
        if ($contact instanceof AbstractContact) {
            $name = trim((string) $contact->getName());

            return '' !== $name ? $name : trim((string) $contact->getSortableName());
        }

        return '';
    }

    /** Morning/afternoon/evening label (boundaries: <12:00, <18:00, else). */
    public function timeBlock(?DateTimeInterface $dateTime): string
    {
        if (null === $dateTime) {
            return '';
        }
        $hour = (int) $dateTime->format('G');
        if ($hour < 12) {
            return 'DOPOLEDNÍ';
        }

        return $hour < 18 ? 'ODPOLEDNÍ' : 'VEČERNÍ';
    }

    /** Roman numeral for an activity series ("Lukostřelba I./II./III."). */
    public function roman(int $number): string
    {
        if ($number <= 0) {
            return (string) $number;
        }
        $map = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
            50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];
        $out = '';
        foreach ($map as $value => $symbol) {
            while ($number >= $value) {
                $out .= $symbol;
                $number -= $value;
            }
        }

        return $out;
    }

    private function resolveContact(mixed $subject): ?AbstractContact
    {
        if ($subject instanceof Participant) {
            return $subject->getContactForRead();
        }
        if ($subject instanceof AbstractContact) {
            return $subject;
        }

        return null;
    }
}
