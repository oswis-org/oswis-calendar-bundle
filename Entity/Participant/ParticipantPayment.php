<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use OswisOrg\OswisCalendarBundle\Traits\Entity\MailConfirmationTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\VariableSymbolTrait;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
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

/**
 * Payment (or return - when numericValue is negative).
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_payment")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_payments_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_payments_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_payment_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_participant_payment_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter::class, properties={
 *     "id": "ASC",
 *     "dateTime",
 *     "createdDateTime",
 *     "numericValue"
 * })
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter::class, properties={
 *     "id": "iexact",
 *     "dateTime": "ipartial",
 *     "createdDateTime": "ipartial",
 *     "numericValue": "ipartial"
 * })
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter::class, properties={
 *     "createdDateTime", "updatedDateTime", "eMailConfirmationDateTime", "dateTime"
 * })
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "dateTime",
 *     "createdDateTime",
 *     "numericValue"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
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

    public const ALLOWED_TYPES = ['', self::TYPE_CASH, self::TYPE_CARD, self::TYPE_BANK_TRANSFER, self::TYPE_ON_LINE];

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\Participant", inversedBy="payments")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     * @Symfony\Component\Serializer\Annotation\MaxDepth(1)
     */
    protected ?Participant $participant = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPaymentsImport")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     * @Symfony\Component\Serializer\Annotation\MaxDepth(1)
     */
    protected ?ParticipantPaymentsImport $import = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?string $errorMessage = null;

    public function __construct(?int $numericValue = null, ?DateTime $dateTime = null, ?string $type = null)
    {
        $this->setNumericValue($numericValue);
        try {
            $this->setType($type);
        } catch (InvalidTypeException $exception) {
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
        if ($this->participant && $this->participant !== $participant) {
            $this->participant->removePayment($this);
        }
        $this->participant = $participant;
        if (null !== $participant) {
            $participant->addPayment($this);
        }
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
