<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
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

/**
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_payments_imports_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar__csv_payments_imports_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar__csv_payments_import_get"}, "enable_max_depth"=true},
 *     }
 *   }
 * )
 */
#[Entity]
#[Table(name: 'calendar_participant_payments_import')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant_payments_import')]
#[ApiFilter(OrderFilter::class, properties: ["id" => "ASC", "createdAt"])]
#[ApiFilter(DateFilter::class, properties: [])]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'createdAt' => 'ipartial'])]
class ParticipantPaymentsImport
{
    use BasicTrait;
    use TypeTrait;
    use NoteTrait;
    use TextValueTrait;

    public const TYPE_CSV       = 'csv';
    public const ALLOWED_TYPES  = [self::TYPE_CSV];
    public const SETTINGS_CODES = ['fio' => 'Fio banka, a.s.'];

    #[Column(type: 'string', nullable: true)]
    public ?string $settingsCode = 'fio';

    /**
     * @param  string|null  $type
     * @param  string|null  $textValue
     * @param  string|null  $note
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
        $csvPaymentRows = array_map(static fn($row) => self::getColumnsFromCsvRow($row, $csvSettings), $csvRows);
        array_walk($csvPaymentRows, static fn(&$a) => $a = array_combine($csvPaymentRows[0], $a));
        array_shift($csvPaymentRows); # remove column header
        foreach ($csvPaymentRows as $csvPaymentRowKey => $csvPaymentRow) {
            $payments->add($this->makePaymentFromCsv($csvPaymentRow, $csvSettings, $csvRows[$csvPaymentRowKey + 1]));
        }

        return $payments;
    }

    private static function getColumnsFromCsvRow(string $row, CsvPaymentImportSettings $csvSettings): array
    {
        return str_getcsv($row, ''.$csvSettings->getDelimiter(), ''.$csvSettings->getEnclosure(),
            ''.$csvSettings->getEscape(),);
    }

    public function makePaymentFromCsv(
        array $csvPaymentRow,
        CsvPaymentImportSettings $csvSettings,
        string $csvRow
    ): ParticipantPayment {
        $csvCurrency = $csvPaymentRow[$csvSettings->getCurrencyColumnName()] ?? null;
        $currencyAllowed = $csvSettings->getCurrencyAllowed();
        $payment = new ParticipantPayment((int)($csvPaymentRow[$csvSettings->getValueColumnName()] ?? 0),
            $this->getDateFromCsvPayment($csvPaymentRow, $csvSettings), ParticipantPayment::TYPE_BANK_TRANSFER);
        $payment->setInternalNote($csvRow);
        $payment->setExternalId($csvPaymentRow[$csvSettings->getIdentifierColumnName()] ?? null);
        if (!$csvCurrency || $csvCurrency !== $currencyAllowed) {
            $payment->setNumericValue(0);
            $payment->setErrorMessage("Wrong payment currency ('$csvCurrency' instead of '$currencyAllowed').");
        }
        $payment->setVariableSymbol($this->getVsFromCsvPayment($csvPaymentRow, $csvSettings));

        return $payment;
    }

    private function getDateFromCsvPayment(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings): ?DateTime
    {
        try {
            $dateKey = (preg_grep('/.*Datum.*/', array_keys($csvPaymentRow)) ?: [])[0] ?? null;
            if (array_key_exists(''.$csvSettings->getDateColumnName(), $csvPaymentRow)) {
                return new DateTime($csvPaymentRow[$csvSettings->getDateColumnName()]);
            }
            if (array_key_exists('"'.$csvSettings->getDateColumnName().'"', $csvPaymentRow)) {
                return new DateTime($csvPaymentRow['"'.$csvSettings->getDateColumnName().'"']);
            }
            if (array_key_exists('\"'.$csvSettings->getDateColumnName().'\"', $csvPaymentRow)) {
                return new DateTime($csvPaymentRow['\"'.$csvSettings->getDateColumnName().'\"']);
            }
            if (array_key_exists($dateKey, $csvPaymentRow)) {
                return new DateTime($csvPaymentRow[$dateKey]);
            }

            return new DateTime();
        } catch (Exception $e) {
            return null;
        }
    }

    public function getVsFromCsvPayment(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings): ?string
    {
        return Participant::vsStringFix($csvPaymentRow[$csvSettings->getVariableSymbolColumnName()] ?? null);
    }
}
