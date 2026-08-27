<?php
/** @noinspection PropertyCanBePrivateInspection */

/** @noinspection PropertyCanBePrivateInspection */
/** @noinspection PropertyCanBePrivateInspection */
/** @noinspection PropertyCanBePrivateInspection */
/** @noinspection PropertyCanBePrivateInspection */
/** @noinspection PropertyCanBePrivateInspection */
/** @noinspection PropertyCanBePrivateInspection */
/** @noinspection PropertyCanBePrivateInspection */
/** @noinspection PropertyCanBePrivateInspection */
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

class CsvPaymentImportSettings
{
    public const DEFAULT_DELIMITER = ';';
    public const DEFAULT_ENCLOSURE = '"';
    public const DEFAULT_ESCAPE = '\\';
    public const DEFAULT_VARIABLE_SYMBOL_COLUMN_NAME = 'VS';
    public const DEFAULT_IDENTIFIER_COLUMN_NAME = 'ID operace';
    public const DEFAULT_DATE_COLUMN_NAME = 'Datum';
    public const DEFAULT_VALUE_COLUMN_NAME = 'Objem';
    public const DEFAULT_CURRENCY_COLUMN_NAME = 'Měna';
    public const DEFAULT_CURRENCY_ALLOWED = 'CZK';

    /**
     * Sloupce výpisu, které se ukládají do vlastních polí platby.
     *
     * Klíč = pole platby, hodnota = název sloupce v hlavičce CSV. Sloupec, který
     * v hlavičce není, se přeskočí — banky se v pojmenování liší a import kvůli
     * chybějícímu detailu padat nesmí.
     *
     * Ověřeno proti hlavičce skutečných výpisů (ČSOB, 27. 8. 2026):
     * "Datum";"Objem";"Měna";"Číslo účtu";"Protiúčet";"Kód banky";"KS";"VS";"SS";
     * "Zpráva pro příjemce";"Poznámka";"ID operace";"Název protiúčtu";"Typ";…
     *
     * @var array<string, string>
     */
    public const DETAIL_COLUMN_NAMES
        = [
            'counterpartyName'    => 'Název protiúčtu',
            'counterpartyAccount' => 'Protiúčet',
            'bankCode'            => 'Kód banky',
            'messageForRecipient' => 'Zpráva pro příjemce',
            'bankOperationType'   => 'Typ',
            'constantSymbol'      => 'KS',
            'specificSymbol'      => 'SS',
        ];

    protected ?string $delimiter = self::DEFAULT_DELIMITER;

    protected ?string $enclosure = self::DEFAULT_ENCLOSURE;

    protected ?string $escape = self::DEFAULT_ESCAPE;

    protected ?string $variableSymbolColumnName = self::DEFAULT_VARIABLE_SYMBOL_COLUMN_NAME;

    protected ?string $identifierColumnName = self::DEFAULT_IDENTIFIER_COLUMN_NAME;

    protected ?string $dateColumnName = self::DEFAULT_DATE_COLUMN_NAME;

    protected ?string $valueColumnName = self::DEFAULT_VALUE_COLUMN_NAME;

    protected ?string $currencyColumnName = self::DEFAULT_CURRENCY_COLUMN_NAME;

    protected ?string $currencyAllowed = self::DEFAULT_CURRENCY_ALLOWED;

    public function getDelimiter(): ?string
    {
        return $this->delimiter ?? self::DEFAULT_DELIMITER;
    }

    public function setDelimiter(?string $delimiter): void
    {
        $this->delimiter = $delimiter;
    }

    public function getEnclosure(): ?string
    {
        return $this->enclosure ?? self::DEFAULT_ENCLOSURE;
    }

    public function setEnclosure(?string $enclosure): void
    {
        $this->enclosure = $enclosure;
    }

    public function getEscape(): ?string
    {
        return $this->escape ?? self::DEFAULT_ESCAPE;
    }

    public function setEscape(?string $escape): void
    {
        $this->escape = $escape;
    }

    public function getVariableSymbolColumnName(): ?string
    {
        return $this->variableSymbolColumnName ?? self::DEFAULT_VARIABLE_SYMBOL_COLUMN_NAME;
    }

    public function setVariableSymbolColumnName(?string $variableSymbolColumnName): void
    {
        $this->variableSymbolColumnName = $variableSymbolColumnName;
    }

    public function getDateColumnName(): ?string
    {
        return $this->dateColumnName ?? self::DEFAULT_DATE_COLUMN_NAME;
    }

    public function setDateColumnName(?string $dateColumnName): void
    {
        $this->dateColumnName = $dateColumnName;
    }

    public function getIdentifierColumnName(): ?string
    {
        return $this->identifierColumnName ?? self::DEFAULT_IDENTIFIER_COLUMN_NAME;
    }

    public function setIdentifierColumnName(?string $identifierColumnName): void
    {
        $this->identifierColumnName = $identifierColumnName;
    }

    public function getValueColumnName(): ?string
    {
        return $this->valueColumnName ?? self::DEFAULT_VALUE_COLUMN_NAME;
    }

    public function setValueColumnName(?string $valueColumnName): void
    {
        $this->valueColumnName = $valueColumnName;
    }

    public function getCurrencyColumnName(): ?string
    {
        return $this->currencyColumnName ?? self::DEFAULT_CURRENCY_COLUMN_NAME;
    }

    public function setCurrencyColumnName(?string $currencyColumnName): void
    {
        $this->currencyColumnName = $currencyColumnName;
    }

    public function getCurrencyAllowed(): ?string
    {
        return $this->currencyAllowed ?? self::DEFAULT_CURRENCY_ALLOWED;
    }

    public function setCurrencyAllowed(?string $currencyAllowed): void
    {
        $this->currencyAllowed = $currencyAllowed;
    }
}
