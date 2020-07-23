<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use OswisOrg\OswisCalendarBundle\Traits\Entity\VariableSymbolTrait;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
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
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_participant_payment_delete"}, "enable_max_depth"=true}
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
 *     "createddDateTime", "updatedDateTime", "eMailConfirmationDateTime", "dateTime"
 * })
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "dateTime",
 *     "createdDateTime",
 *     "numericValue"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantPayment
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

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\Participant", inversedBy="payments"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     * @Symfony\Component\Serializer\Annotation\MaxDepth(1)
     */
    protected ?Participant $participant = null;

    /**
     * @param int|null      $numericValue
     * @param DateTime|null $dateTime
     * @param string|null   $type
     *
     * @throws InvalidTypeException
     */
    public function __construct(?int $numericValue = null, ?DateTime $dateTime = null, ?string $type = null)
    {
        $this->setNumericValue($numericValue);
        $this->setType($type);
        try {
            $this->setDateTime($dateTime);
        } catch (NotImplementedException $exception) {}
    }

    /**
     * @param DateTime|null $dateTime
     *
     * @throws NotImplementedException
     */
    public function setDateTime(?DateTime $dateTime): void
    {
        $oldDateTime = $this->getDateTime();
        if (null !== $oldDateTime && $oldDateTime !== $dateTime) {
            throw new NotImplementedException('změna data platby');
        }
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

    public static function getAllowedTypesDefault(): array
    {
        return ['', 'cash', 'bank-transfer', 'card', 'on-line'];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
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
        if (null !== $this->participant || null === $participant) {
            throw new NotImplementedException('změna účastníka', 'u platby');
        }
        $this->participant = $participant;
        $participant->addPayment($this);
    }
}
