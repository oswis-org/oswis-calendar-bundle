<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CsvPaymentImportSettings;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NoteTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_payments_import")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_payments_imports_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar__csv_payments_imports_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar__csv_payments_import_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={},
 *     "delete"={}
 *   }
 * )
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter::class, properties={
 *     "id": "ASC",
 *     "createdDateTime"
 * })
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter::class, properties={
 *     "id": "iexact",
 *     "createdDateTime": "ipartial"
 * })
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter::class, properties={
 *     "createdDateTime"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant_payments_import")
 */
class ParticipantPaymentsImport
{
    use BasicTrait;
    use TypeTrait;
    use NoteTrait;
    use TextValueTrait;

    public const TYPE_CSV = 'csv';

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
        return ['', self::TYPE_CSV];
    }

    public function extractPayments(CsvPaymentImportSettings $csvSettings): Collection
    {
        $payments = new ArrayCollection();
        $csvRows = str_getcsv($this->getTextValue(), "\n");
        $csvPaymentRows = array_map(fn($row) => self::getColumnsFromCsvRow($row, $csvSettings), $csvRows);
        array_walk($csvPaymentRows, fn(&$a) => $a = array_combine($csvPaymentRows[0], $a));
        array_shift($csvPaymentRows); # remove column header
        foreach ($csvPaymentRows as $csvPaymentRowKey => $csvPaymentRow) {
            $payments->add($this->makePaymentFromCsv($csvPaymentRow, $csvSettings, $csvRows[$csvPaymentRowKey]));
        }

        return $payments;
    }

    private static function getColumnsFromCsvRow(string $row, CsvPaymentImportSettings $csvSettings): array
    {
        return str_getcsv($row, $csvSettings->getDelimiter(), $csvSettings->getEnclosure(), $csvSettings->getEscape());
    }

    public function makePaymentFromCsv(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings, string $csvRow): ParticipantPayment
    {
        $csvCurrency = $csvPaymentRow[$csvSettings->getCurrencyColumnName()] ?? null;
        $currencyAllowed = $csvSettings->getCurrencyAllowed();
        $payment = new ParticipantPayment(
            (int)($csvPaymentRow[$csvSettings->getValueColumnName()] ?? 0), $this->getDateFromCsvPayment($csvPaymentRow, $csvSettings), ParticipantPayment::TYPE_BANK_TRANSFER
        );
        $payment->setInternalNote($csvRow);
        $payment->setExternalId($csvPaymentRow[$csvSettings->getValueColumnName()] ?? null);
        // TODO: Check duplicity of externalID in service.
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
            $dateKey = preg_grep('/.*Datum.*/', array_keys($csvPaymentRow))[0] ?? null;
            if (array_key_exists($csvSettings->getDateColumnName(), $csvPaymentRow)) {
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