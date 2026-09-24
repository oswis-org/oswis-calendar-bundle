<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Mail;

use Doctrine\DBAL\Connection;
use OswisOrg\OswisCoreBundle\Interfaces\AddressBook\ContactInterface;
use OswisOrg\OswisCoreBundle\Mail\Audience\MailAudienceField as F;
use OswisOrg\OswisCoreBundle\Mail\Audience\MailAudienceProviderInterface;

/**
 * Údaje stavebnice podmínky nad slovníkem filtru přihlášek ({@see \OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantFilterEvaluator}).
 * Klíč = funkce filtru; stavebnice z nich skládá výraz, který vyhodnotí `pravidla()` v šabloně mailu.
 *
 * Volby jen z toho, co se opravdu používá: akce s přihláškami a příznaky, které nějaká přihláška má
 * (klon 24. 9. 2026: 29 akcí, 31 příznaků v 7 kategoriích) — ne stovky historických nabídek.
 */
final readonly class ParticipantAudienceProvider implements MailAudienceProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function getFields(): iterable
    {
        yield new F('remainingPrice', 'Zbývá zaplatit (Kč)', F::NUMBER);
        yield new F('remainingDeposit', 'Zbývá záloha (Kč)', F::NUMBER);
        yield new F('isPaid', 'Má zaplaceno vše', F::BOOL);
        yield new F('isOverpaid', 'Přeplatil(a)', F::BOOL);
        yield new F('isConfirmed', 'Potvrdil(a) přihlášku odkazem z e-mailu', F::BOOL);
        yield new F('isActivated', 'Má aktivovaný účet', F::BOOL);
        yield new F('hasNote', 'Má u přihlášky poznámku', F::BOOL);
        yield new F('gender', 'Pohlaví', F::CHOICE, [
            ['value' => ContactInterface::GENDER_FEMALE, 'label' => 'žena'],
            ['value' => ContactInterface::GENDER_MALE, 'label' => 'muž'],
        ]);
        yield new F('eventSlug', 'Akce (turnus)', F::CHOICE, $this->akce());
        yield new F('hasFlag', 'Příznak', F::FLAG, $this->priznaky());
        yield new F('hasFlagInCategory', 'Některý příznak z kategorie', F::FLAG, $this->kategorie());
    }

    /** @return list<array{value: string, label: string}> */
    private function akce(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.slug, COALESCE(NULLIF(e.short_name, \'\'), e.name) nazev FROM calendar_event e
              WHERE e.slug IS NOT NULL AND e.id IN (SELECT event_id FROM calendar_participant WHERE deleted_at IS NULL)
              ORDER BY e.start_date_time DESC',
        );

        return self::volby($rows, 'slug', 'nazev');
    }

    /** @return list<array{value: string, label: string, group?: string}> */
    private function priznaky(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT f.slug, f.name nazev, c.name skupina FROM calendar_participant_flag pf
               JOIN calendar_flag_range r ON r.id = pf.flag_offer_id
               JOIN calendar_flag f ON f.id = r.flag_id
               LEFT JOIN calendar_flag_category c ON c.id = f.category_id
              WHERE pf.deleted_at IS NULL AND f.slug IS NOT NULL
              ORDER BY c.name, f.name',
        );

        return self::volby($rows, 'slug', 'nazev', 'skupina');
    }

    /** @return list<array{value: string, label: string}> */
    private function kategorie(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT c.slug, c.name nazev FROM calendar_participant_flag pf
               JOIN calendar_flag_range r ON r.id = pf.flag_offer_id
               JOIN calendar_flag f ON f.id = r.flag_id
               JOIN calendar_flag_category c ON c.id = f.category_id
              WHERE pf.deleted_at IS NULL AND c.slug IS NOT NULL
              ORDER BY c.name',
        );

        return self::volby($rows, 'slug', 'nazev');
    }

    /**
     * Řádky z DB → volby; jen hodnoty, které stavebnice umí zapsat (slug bez uvozovek a mezer).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{value: string, label: string, group?: string}>
     */
    private static function volby(array $rows, string $value, string $label, ?string $group = null): array
    {
        $volby = [];
        foreach ($rows as $row) {
            $hodnota = $row[$value] ?? null;
            if (!is_string($hodnota) || 1 !== preg_match('/^[A-Za-z0-9_.\-]+$/', $hodnota)) {
                continue;
            }
            $popisek = $row[$label] ?? null;
            $volba = ['value' => $hodnota, 'label' => is_string($popisek) && '' !== $popisek ? $popisek : $hodnota];
            $skupina = null === $group ? null : ($row[$group] ?? null);
            if (is_string($skupina) && '' !== $skupina) {
                $volba['group'] = $skupina;
            }
            $volby[] = $volba;
        }

        return $volby;
    }
}
