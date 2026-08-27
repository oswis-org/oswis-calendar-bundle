<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CsvPaymentImportSettings;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Filter\SearchFilter;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NoteTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_participant_payments_imports_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['calendar__csv_payments_imports_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar__csv_payments_import_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
    ],
    filters: ['search'],
    security: "is_granted('ROLE_MANAGER')"
)]
#[Entity]
#[Table(name: 'calendar_participant_payments_import')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant_payments_import')]
#[ApiFilter(OrderFilter::class, properties: ['id' => 'ASC', 'createdAt'])]
#[ApiFilter(DateFilter::class)]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'createdAt' => 'ipartial'])]
class ParticipantPaymentsImport
{
    use BasicTrait;
    use TypeTrait;
    use NoteTrait;
    use TextValueTrait;

    public const TYPE_CSV = 'csv';
    public const ALLOWED_TYPES = [self::TYPE_CSV];
    public const SETTINGS_CODES = ['fio' => 'Fio banka, a.s.'];

    #[Column(type: 'string', nullable: true)]
    public ?string $settingsCode = 'fio';

    /**
     * Kdy byl tenhle import ZPRACOVÁN — zámek proti dvojímu zpracování.
     *
     * ⚠️ Bez něj vložil import #240 dne 16. 8. 2026 každou platbu dvakrát: 102 fiktivních plateb
     * za 349 735 Kč u 93 účastníků. Spustit jeden import dvakrát umí tři různé cesty
     * ({@see ParticipantPaymentsImportService::processImport()}), takže zámek musí být na datech,
     * ne v některé z nich. `null` = ještě nezpracován.
     */
    #[Column(name: 'processed_at', type: 'datetime', nullable: true)]
    protected ?DateTime $processedAt = null;

    public function getProcessedAt(): ?DateTime
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?DateTime $processedAt): void
    {
        $this->processedAt = $processedAt;
    }

    /**
     * @param string|null $type
     * @param string|null $textValue
     * @param string|null $note
     *
     * @throws InvalidTypeException
     */
    public function __construct(?string $type = null, ?string $textValue = null, ?string $note = null)
    {
        $this->setTextValue($textValue);
        $this->setNote($note);
        $this->setType($type);
    }

    public static function getAllowedTypesDefault(): array
    {
        return self::ALLOWED_TYPES;
    }

    public function getSettings(?string $settingsCode = null): CsvPaymentImportSettings
    {
        return new CsvPaymentImportSettings();
    }

    public function extractPayments(CsvPaymentImportSettings $csvSettings): Collection
    {
        $payments = new ArrayCollection();
        // Rozdělení na řádky (oddělovač = konec řádku). Enclosure i escape se předávají VÝSLOVNĚ
        // v dosavadních výchozích hodnotách: od PHP 8.4 je vynechání `$escape` deprecated a jeho
        // výchozí hodnota se má změnit — což by u importu plateb tiše změnilo parsování dat.
        $csvRows = str_getcsv(''.$this->getTextValue(), "\n", '"', "\\");
        // První řádek je hlavička — dává sloupcům jména, pod kterými se pak čtou.
        // Pořadí sloupců se mezi bankami liší, takže na pozice se spoléhat nelze.
        $hlavicka = array_map(
            static fn (?string $nazev): string => $nazev ?? '',
            self::getColumnsFromCsvRow(''.$csvRows[0], $csvSettings),
        );
        foreach ($csvRows as $poradi => $syrovyRadek) {
            if (0 === $poradi) {
                continue;
            }
            $hodnoty = self::getColumnsFromCsvRow(''.$syrovyRadek, $csvSettings);
            if (count($hodnoty) !== count($hlavicka)) {
                // Řádek s jiným počtem sloupců než hlavička se spojit nedá; `array_combine()`
                // by na něm skončil chybou a shodil celý import.
                continue;
            }
            /** @var array<string, ?string> $radek */
            $radek = array_combine($hlavicka, $hodnoty);
            $payments->add($this->makePaymentFromCsv($radek, $csvSettings, ''.$syrovyRadek));
        }

        return $payments;
    }

    /**
     * @param string                   $row
     * @param CsvPaymentImportSettings $csvSettings
     *
     * @return array<?string>
     */
    private static function getColumnsFromCsvRow(string $row, CsvPaymentImportSettings $csvSettings): array
    {
        return str_getcsv(
            $row,
            ''.$csvSettings->getDelimiter(),
            ''.$csvSettings->getEnclosure(),
            ''.$csvSettings->getEscape(),
        );
    }

    /**
     * Řádek výpisu je klíčovaný NÁZVY SLOUPCŮ z hlavičky (viz `extractPayments()`,
     * kde se přes `array_combine()` spojí hlavička s hodnotami) — ne pořadím.
     *
     * @param array<string, mixed> $csvPaymentRow
     */
    public function makePaymentFromCsv(
        array $csvPaymentRow,
        CsvPaymentImportSettings $csvSettings,
        string $csvRow
    ): ParticipantPayment {
        $csvCurrency = $csvPaymentRow[(string) $csvSettings->getCurrencyColumnName()] ?? null;
        $currencyAllowed = $csvSettings->getCurrencyAllowed();
        $dateTime = $this->getDateFromCsvPayment($csvPaymentRow, $csvSettings);
        $payment = new ParticipantPayment(
            self::toInt($csvPaymentRow[(string) $csvSettings->getValueColumnName()] ?? 0),
            $dateTime,
            ParticipantPayment::TYPE_BANK_TRANSFER
        );
        // Syrový řádek patří do vlastního pole, ne do interní poznámky: tu formulář
        // „Upravit platbu" nabízí k volnému psaní a přepsáním by se audit ztratil.
        $payment->setBankRawRow($csvRow);
        $payment->setCurrency(self::toString($csvCurrency));
        self::vyplnDetailyZVypisu($payment, $csvPaymentRow);
        $payment->setExternalId(self::toString($csvPaymentRow[(string) $csvSettings->getIdentifierColumnName()] ?? null));
        $errors = [];
        if (!$csvCurrency || $csvCurrency !== $currencyAllowed) {
            $payment->setNumericValue(0);
            $csvCurrencyString = self::toString($csvCurrency);
            $currencyAllowedString = self::toString($currencyAllowed);
            $errors[] = "Wrong payment currency ('$csvCurrencyString' instead of '$currencyAllowedString').";
        }
        if (null === $dateTime) {
            // Without this the payment silently inherits the import time and skews the matcher's
            // date-proximity score. The raw CSV row stays in internalNote for manual correction.
            $errors[] = 'Datum platby se nepodařilo přečíst z CSV řádku.';
        }
        if ([] !== $errors) {
            $payment->setErrorMessage(implode(' ', $errors));
        }
        $payment->setVariableSymbol($this->getVsFromCsvPayment($csvPaymentRow, $csvSettings));

        return $payment;
    }

    /**
     * Rozepíše sloupce výpisu do vlastních polí platby.
     *
     * Bez toho zůstávaly „Zpráva pro příjemce" a „Název protiúčtu" jen uvnitř syrového
     * řádku — tedy nedohledatelné dotazem a v administraci k přečtení jen po očích.
     * Přitom právě podle nich se dohledává, kdo platbu poslal (viz rozlišování
     * duplicitních přihlášek).
     *
     * @param array<string, mixed> $csvPaymentRow
     */
    private static function vyplnDetailyZVypisu(ParticipantPayment $payment, array $csvPaymentRow): void
    {
        $sloupce = CsvPaymentImportSettings::DETAIL_COLUMN_NAMES;
        $hodnota = static fn (string $klic): ?string => isset($sloupce[$klic])
            ? self::toString($csvPaymentRow[$sloupce[$klic]] ?? null)
            : null;

        $payment->setCounterpartyName($hodnota('counterpartyName'));
        $payment->setMessageForRecipient($hodnota('messageForRecipient'));
        $payment->setBankOperationType($hodnota('bankOperationType'));
        $payment->setConstantSymbol($hodnota('constantSymbol'));
        $payment->setSpecificSymbol($hodnota('specificSymbol'));

        // Číslo účtu je ve výpisu rozdělené na dva sloupce; dohromady dává tvar,
        // který jde porovnat s tím, co lidé píšou do e-mailů.
        $ucet = $hodnota('counterpartyAccount');
        $kodBanky = $hodnota('bankCode');
        $payment->setCounterpartyAccount(match (true) {
            null !== $ucet && null !== $kodBanky => $ucet.'/'.$kodBanky,
            default                              => $ucet,
        });
    }

    /**
     * Converts mixed value to integer.
     *
     * @param mixed    $value   Value to convert
     * @param int|null $default Default value if conversion fails (defaults to 0)
     * @return int Converted integer value
     */
    public static function toInt(mixed $value, ?int $default = 0): int
    {
        // Handle null or empty values
        if ($value === null || $value === '') {
            return $default ?? 0;
        }

        // Handle boolean values
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        // Handle numeric strings and numbers
        if (is_numeric($value)) {
            return (int)$value;
        }

        // Handle string values that might contain numbers
        if (is_string($value)) {
            // Extract first number from string
            if (preg_match('/[-+]?\d+/', $value, $matches)) {
                return (int)$matches[0];
            }
        }

        // Return default for all other cases
        return $default ?? 0;
    }

    /**
     * Reads the transaction date from a CSV row, or null when the row carries none we can read.
     *
     * Null is deliberate: the old code fell back to `new DateTime()` (the import time) and swallowed
     * every parse failure into null as well. `ParticipantPayment::getDateTime()` then falls back to
     * `createdAt` — also the import time. Either way the payment entered the matcher's date-proximity
     * score stamped with the wrong day and nothing anywhere said so. makePaymentFromCsv() now turns a
     * null into a visible error message on the payment instead.
     */
    private function getDateFromCsvPayment(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings): ?DateTime
    {
        $dateColumnName = ''.$csvSettings->getDateColumnName();
        // The exporting bank quotes (and sometimes escapes) header cells inconsistently, so the
        // configured column name is tried raw, quoted and escaped-quoted. preg_grep PRESERVES keys
        // ('Datum' is rarely at index 0) — [0] on the raw result raised an undefined-key warning,
        // which Symfony's ErrorHandler turns into an ErrorException inside a web request.
        $candidates = [$dateColumnName, '"'.$dateColumnName.'"', "\\\"{$dateColumnName}\\\""];
        if ('' !== $dateColumnName) {
            // Poslední pokus: jakýkoli sloupec, jehož název konfigurovaný název obsahuje (jinak
            // zabalený do uvozovek či jinak ozdobený). Dřív tu bylo natvrdo „Datum" bez ohledu
            // na nastavení, takže jiná než česká Fio hlavička by sem nikdy nedosáhla.
            $pattern = '/'.preg_quote($dateColumnName, '/').'/i';
            foreach (array_values(preg_grep($pattern, array_keys($csvPaymentRow)) ?: []) as $matchedKey) {
                $candidates[] = self::toString($matchedKey);
            }
        }
        foreach ($candidates as $candidate) {
            if ('' === $candidate || !array_key_exists($candidate, $csvPaymentRow)) {
                continue;
            }
            $rawDate = self::toString($csvPaymentRow[$candidate]);
            if ('' === $rawDate) {
                continue;
            }
            try {
                return new DateTime($rawDate);
            } catch (Exception) {
                // Unreadable value in this column — keep looking, report only if nothing works.
            }
        }

        return null;
    }

    /**
     * Converts mixed value to string.
     *
     * @param mixed       $value   Value to convert
     * @param string|null $default Default value if conversion fails (defaults to '')
     * @return string Converted string value
     */
    public static function toString(mixed $value, ?string $default = ''): string
    {
        // Handle null
        if ($value === null) {
            return $default ?? '';
        }

        // Handle boolean values
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // Handle arrays
        if (is_array($value)) {
            return implode(
                ', ',
                array_map(
                    fn ($item) => is_scalar($item) ? (string)$item : gettype($item),
                    $value
                )
            );
        }

        // Handle objects
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return self::toString($value->__toString());
            }
            if (method_exists($value, 'toString')) {
                return self::toString($value->toString());
            }

            return get_class($value);
        }

        // Handle resources
        if (is_resource($value)) {
            return get_resource_type($value);
        }

        // Handle scalar values (strings, integers, floats)
        if (is_scalar($value)) {
            return (string)$value;
        }

        // Return default for all other cases
        return $default ?? '';
    }

    public function getVsFromCsvPayment(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings): ?string
    {
        return Participant::vsStringFix(self::toString($csvPaymentRow[(string) $csvSettings->getVariableSymbolColumnName()] ?? null));
    }
}
