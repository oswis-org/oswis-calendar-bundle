<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use DateTime;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantPaymentRepository;
use OswisOrg\OswisCalendarBundle\Traits\Entity\MailConfirmationTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\VariableSymbolTrait;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Filter\SearchFilter;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\MyDateTimeInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\TypeInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DateTimeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ExternalIdTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\InternalNoteTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NoteTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NumericValueTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;
use Symfony\Component\Serializer\Attribute\MaxDepth;

/**
 * Payment (or return - when numericValue is negative).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_participant_payments_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['calendar_participant_payments_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar_participant_payment_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Put(
            denormalizationContext: ['groups' => ['calendar_participant_payment_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')"
        ),
    ],
    security: "is_granted('ROLE_MANAGER')"
)]
#[Entity(repositoryClass: ParticipantPaymentRepository::class)]
#[Table(name: 'calendar_participant_payment')]
// Perf: výpis plateb ORDER BY date_time DESC LIMIT 500 (participant_id už má FK index).
#[Index(name: 'idx_payment_date_time', columns: ['date_time'])]
// ⚠️ JEDINÁ spolehlivá pojistka proti duplicitám z importu. Deduplikace v kódu selhala
// 16. 8. 2026 (četla přes zastaralou L2 cache) a import vložil 102 plateb dvakrát — 349 735 Kč
// u 93 lidí. Databáze to teď nedovolí bez ohledu na cache, počet volajících i dvojí odeslání
// formuláře. Rozbor: docs/OSWIS_1_INCIDENT_PAYMENT_DUPLICATES_2026-08-16.md
#[UniqueConstraint(name: 'uniq_payment_external_id', columns: ['external_id'])]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
#[ApiFilter(DateFilter::class, properties: [
    "createdDateTime",
    "updatedDateTime",
    "eMailConfirmationDateTime",
    "dateTime",
])]
#[ApiFilter(SearchFilter::class, properties: [
    "id" => "iexact",
    "dateTime" => "ipartial",
    "createdDateTime" => "ipartial",
    "numericValue" => "ipartial",
])]
#[ApiFilter(OrderFilter::class, properties: [
    "id" => "ASC",
    "dateTime",
    "createdDateTime",
    "numericValue",
])]
#[SearchAnnotation(['id', 'dateTime', 'createdDateTime', 'numericValue'])]
class ParticipantPayment implements BasicInterface, TypeInterface, MyDateTimeInterface
{
    use BasicTrait;
    use NumericValueTrait;
    use TypeTrait;
    use NoteTrait;
    use InternalNoteTrait;
    use ExternalIdTrait;
    use DateTimeTrait {
        getDateTime as protected traitGetDateTime;
    }
    use VariableSymbolTrait;
    use MailConfirmationTrait;

    public const TYPE_BANK_TRANSFER = 'bank-transfer';
    public const TYPE_CARD = 'card';
    public const TYPE_CASH = 'cash';
    public const TYPE_ON_LINE = 'on-line';
    public const TYPE_INTERNAL = 'internal';
    public const ALLOWED_TYPES
        = [
            '',
            self::TYPE_CASH,
            self::TYPE_CARD,
            self::TYPE_BANK_TRANSFER,
            self::TYPE_ON_LINE,
            self::TYPE_INTERNAL,
        ];
    #[ManyToOne(targetEntity: Participant::class, inversedBy: 'payments', fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    #[MaxDepth(1)]
    protected ?Participant $participant = null;

    #[ManyToOne(targetEntity: ParticipantPaymentsImport::class)]
    #[JoinColumn(nullable: true)]
    #[MaxDepth(1)]
    protected ?ParticipantPaymentsImport $import = null;

    #[Column(type: 'string', nullable: true)]
    protected ?string $errorMessage = null;

    /**
     * Přesný řádek z bankovního výpisu, tak jak přišel. AUDIT — needitovat.
     *
     * Do 27. 8. 2026 se ukládal do `internalNote`, tedy do pole, které formulář
     * „Upravit platbu" nabízí jako **Interní poznámka** k volnému psaní. Kdo do ní
     * napsal, ten původní řádek z banky nenávratně přepsal — a nikde se to neohlásilo.
     * Na klonu měly ten řádek v poznámce **všechny 5013 importované platby**, zatímco
     * lidskou poznámku tam neměla ani jedna: pole bylo obsazené strojovými daty
     * a nikdo ho nemohl použít k tomu, k čemu je.
     */
    #[Column(name: 'bank_raw_row', type: 'text', nullable: true)]
    protected ?string $bankRawRow = null;

    /** Název protiúčtu — kdo platbu poslal. */
    #[Column(name: 'counterparty_name', type: 'string', length: 255, nullable: true)]
    protected ?string $counterpartyName = null;

    /** Protiúčet i s kódem banky ve tvaru `1465708003/0800`. */
    #[Column(name: 'counterparty_account', type: 'string', length: 64, nullable: true)]
    protected ?string $counterpartyAccount = null;

    /** „Zpráva pro příjemce" — to, co plátce napsal. Často jediné vodítko k párování. */
    #[Column(name: 'message_for_recipient', type: 'string', length: 255, nullable: true)]
    protected ?string $messageForRecipient = null;

    /** Typ operace podle banky, např. „Okamžitá příchozí platba". */
    #[Column(name: 'bank_operation_type', type: 'string', length: 100, nullable: true)]
    protected ?string $bankOperationType = null;

    #[Column(name: 'constant_symbol', type: 'string', length: 16, nullable: true)]
    protected ?string $constantSymbol = null;

    #[Column(name: 'specific_symbol', type: 'string', length: 16, nullable: true)]
    protected ?string $specificSymbol = null;

    /** Měna z výpisu. Dosud se jen kontrolovala proti povolené a nikam se neukládala. */
    #[Column(name: 'currency', type: 'string', length: 8, nullable: true)]
    protected ?string $currency = null;

    public function __construct(?int $numericValue = null, ?DateTime $dateTime = null, ?string $type = null)
    {
        $this->setNumericValue($numericValue);
        try {
            $this->setType($type);
        } catch (InvalidTypeException) {
        }
        $this->setDateTime($dateTime);
    }

    public function setDateTime(?DateTime $dateTime): void
    {
        $this->dateTime = $dateTime;
    }

    public static function getAllowedTypesDefault(): array
    {
        return self::ALLOWED_TYPES;
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    /**
     * Date and time of payment.
     *
     * Date and time of creation is returned if it's not overwritten by dateTime property.
     * This method overrides method from trait.
     *
     * @return DateTime|null
     */
    public function getDateTime(): ?DateTime
    {
        return $this->traitGetDateTime() ?? $this->getCreatedAt();
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getBankRawRow(): ?string
    {
        return $this->bankRawRow;
    }

    public function setBankRawRow(?string $bankRawRow): void
    {
        $this->bankRawRow = $bankRawRow;
    }

    public function getCounterpartyName(): ?string
    {
        return $this->counterpartyName;
    }

    public function setCounterpartyName(?string $counterpartyName): void
    {
        $this->counterpartyName = self::orez($counterpartyName, 255);
    }

    public function getCounterpartyAccount(): ?string
    {
        return $this->counterpartyAccount;
    }

    public function setCounterpartyAccount(?string $counterpartyAccount): void
    {
        $this->counterpartyAccount = self::orez($counterpartyAccount, 64);
    }

    public function getMessageForRecipient(): ?string
    {
        return $this->messageForRecipient;
    }

    public function setMessageForRecipient(?string $messageForRecipient): void
    {
        $this->messageForRecipient = self::orez($messageForRecipient, 255);
    }

    public function getBankOperationType(): ?string
    {
        return $this->bankOperationType;
    }

    public function setBankOperationType(?string $bankOperationType): void
    {
        $this->bankOperationType = self::orez($bankOperationType, 100);
    }

    public function getConstantSymbol(): ?string
    {
        return $this->constantSymbol;
    }

    public function setConstantSymbol(?string $constantSymbol): void
    {
        $this->constantSymbol = self::orez($constantSymbol, 16);
    }

    public function getSpecificSymbol(): ?string
    {
        return $this->specificSymbol;
    }

    public function setSpecificSymbol(?string $specificSymbol): void
    {
        $this->specificSymbol = self::orez($specificSymbol, 16);
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void
    {
        $this->currency = self::orez($currency, 8);
    }

    /**
     * Hodnoty jdou z cizího CSV, takže se na délku sloupce nedá spolehnout — bez ořezu
     * by delší jméno protiúčtu shodilo celý import na chybě z databáze.
     */
    private static function orez(?string $hodnota, int $delka): ?string
    {
        if (null === $hodnota) {
            return null;
        }
        $orezane = mb_substr(trim($hodnota), 0, $delka);

        return '' === $orezane ? null : $orezane;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    /**
     * @param Participant|null $participant
     *
     * @throws NotImplementedException
     */
    public function setParticipant(?Participant $participant): void
    {
        if ($this->participant === $participant) {
            return;
        }
        if (null !== $this->participant && (null !== $this->getId() && null === $participant)) {
            // Do not allow to remove payment from participant if payment was already persisted.
            throw new NotImplementedException('změna účastníka', 'u platby');
        }
        $this->participant?->removePayment($this);
        $this->participant = $participant;
        $participant?->addPayment($this);
    }

    /**
     * Admin-sanctioned detach (manual re-matching in the web admin). The generic
     * {@see setParticipant()} deliberately forbids null on a persisted payment (protects the
     * API write path), but the curated admin "Odpojit" flow must be able to undo a wrong match.
     */
    public function detachParticipant(): void
    {
        $this->participant?->removePayment($this);
        $this->participant = null;
    }

    public function getImport(): ?ParticipantPaymentsImport
    {
        return $this->import;
    }

    public function setImport(?ParticipantPaymentsImport $import): void
    {
        $this->import = $import;
    }
}
