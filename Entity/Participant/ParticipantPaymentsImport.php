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
        $csvRows = str_getcsv(''.$this->getTextValue(), "\n");
        $csvPaymentRows = array_map(static fn ($row) => self::getColumnsFromCsvRow(''.$row, $csvSettings), $csvRows);
        array_walk($csvPaymentRows, static fn (&$a) => $a = array_combine(
            array_map(static fn (?string $item): string => $item ?? '', $csvPaymentRows[0]),
            $a,
        ));
        array_shift($csvPaymentRows); # remove column header
        foreach ($csvPaymentRows as $csvPaymentRowKey => $csvPaymentRow) {
            $payments->add($this->makePaymentFromCsv($csvPaymentRow, $csvSettings, ''.$csvRows[$csvPaymentRowKey + 1]));
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

    public function makePaymentFromCsv(
        array $csvPaymentRow,
        CsvPaymentImportSettings $csvSettings,
        string $csvRow
    ): ParticipantPayment {
        $csvCurrency = $csvPaymentRow[$csvSettings->getCurrencyColumnName()] ?? null;
        $currencyAllowed = $csvSettings->getCurrencyAllowed();
        $payment = new ParticipantPayment(
            self::toInt($csvPaymentRow[$csvSettings->getValueColumnName()] ?? 0),
            $this->getDateFromCsvPayment($csvPaymentRow, $csvSettings),
            ParticipantPayment::TYPE_BANK_TRANSFER
        );
        $payment->setInternalNote($csvRow);
        $payment->setExternalId(self::toString($csvPaymentRow[$csvSettings->getIdentifierColumnName()] ?? null));
        if (!$csvCurrency || $csvCurrency !== $currencyAllowed) {
            $payment->setNumericValue(0);
            $csvCurrencyString = self::toString($csvCurrency);
            $currencyAllowedString = self::toString($currencyAllowed);
            $payment->setErrorMessage("Wrong payment currency ('$csvCurrencyString' instead of '$currencyAllowedString').");
        }
        $payment->setVariableSymbol($this->getVsFromCsvPayment($csvPaymentRow, $csvSettings));

        return $payment;
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

    private function getDateFromCsvPayment(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings): ?DateTime
    {
        try {
            $dateKey = self::toString((preg_grep('/.*Datum.*/', array_keys($csvPaymentRow)) ?: [])[0]);
            $dateColumnName = $csvSettings->getDateColumnName();
            if (array_key_exists(''.$dateColumnName, $csvPaymentRow)) {
                $dateColumnValue = self::toString($csvPaymentRow[$dateColumnName]);

                return new DateTime($dateColumnValue);
            }
            if (array_key_exists('"'.$dateColumnName.'"', $csvPaymentRow)) {
                $dateColumnValue = self::toString($csvPaymentRow["\"$dateColumnName\""]);

                return new DateTime($dateColumnValue);
            }
            if (array_key_exists("\\\"{$dateColumnName}\\\"", $csvPaymentRow)) {
                $dateColumnValue = self::toString($csvPaymentRow["\\\"{$dateColumnName}\\\""]);

                return new DateTime($dateColumnValue);
            }
            if (array_key_exists($dateKey, $csvPaymentRow)) {
                return new DateTime(self::toString($csvPaymentRow[$dateKey]));
            }

            return new DateTime();
        } catch (Exception $e) {
            return null;
        }
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
        return Participant::vsStringFix(self::toString($csvPaymentRow[$csvSettings->getVariableSymbolColumnName()] ?? null));
    }
}
