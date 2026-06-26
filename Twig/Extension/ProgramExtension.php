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
    private const array CZECH_WEEKDAYS = [
        1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek', 5 => 'pátek', 6 => 'sobota', 7 => 'neděle',
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('staff_name', $this->staffName(...)),
            new TwigFilter('time_block', $this->timeBlock(...)),
            new TwigFilter('roman', $this->roman(...)),
            new TwigFilter('cz_weekday', $this->czechWeekday(...)),
            new TwigFilter('cz_date', $this->czechDate(...)),
            new TwigFilter('hm', $this->hourMinute(...)),
            new TwigFilter('contrast_color', $this->contrastColor(...)),
        ];
    }

    /** Black or white — whichever reads on the given hex background (e.g. white on MODRÁ, black on ŽLUTÁ). */
    public function contrastColor(mixed $hex): string
    {
        if (!is_string($hex)) {
            return '#fff';
        }
        $h = ltrim($hex, '#');
        if (3 === strlen($h)) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (6 !== strlen($h) || !ctype_xdigit($h)) {
            return '#fff';
        }
        $luminance = 0.299 * hexdec(substr($h, 0, 2)) + 0.587 * hexdec(substr($h, 2, 2)) + 0.114 * hexdec(substr($h, 4, 2));

        return $luminance > 150 ? '#111' : '#fff';
    }

    /** Czech weekday name ("úterý") from a 'Y-m-d' string or DateTimeInterface. */
    public function czechWeekday(mixed $value): string
    {
        $date = $this->toDate($value);

        return null === $date ? '' : self::CZECH_WEEKDAYS[(int) $date->format('N')];
    }

    /** Czech short date "10. 9." from a 'Y-m-d' string or DateTimeInterface. */
    public function czechDate(mixed $value): string
    {
        $date = $this->toDate($value);

        return null === $date ? '' : ((int) $date->format('j') . '. ' . (int) $date->format('n') . '.');
    }

    /** "9:00" from a 'H:i' string (strips the hour's leading zero). */
    public function hourMinute(mixed $value): string
    {
        if (!is_string($value) || !str_contains($value, ':')) {
            return is_string($value) ? $value : '';
        }
        [$hour, $minute] = explode(':', $value, 2);

        return (int) $hour . ':' . $minute;
    }

    private function toDate(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (is_string($value) && '' !== $value) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
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
