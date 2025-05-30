<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

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
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
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
use Symfony\Component\Serializer\Annotation\MaxDepth;

/**
 * Payment (or return - when numericValue is negative).
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "dateTime",
 *     "createdDateTime",
 *     "numericValue"
 * })
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
#[Entity]
#[Table(name: 'calendar_participant_payment')]
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
    #[ManyToOne(targetEntity: Participant::class, inversedBy: 'payments')]
    #[JoinColumn(nullable: true)]
    #[MaxDepth(1)]
    protected ?Participant $participant = null;

    #[ManyToOne(targetEntity: ParticipantPaymentsImport::class)]
    #[JoinColumn(nullable: true)]
    #[MaxDepth(1)]
    protected ?ParticipantPaymentsImport $import = null;

    #[Column(type: 'string', nullable: true)]
    protected ?string $errorMessage = null;

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

    public function getImport(): ?ParticipantPaymentsImport
    {
        return $this->import;
    }

    public function setImport(?ParticipantPaymentsImport $import): void
    {
        $this->import = $import;
    }
}
