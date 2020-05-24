<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use DateTime;
use InvalidArgumentException;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractPayment;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Symfony\Component\Serializer\Annotation\MaxDepth;

/**
 * Payment (or return - when numericValue is negative).
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_payment")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_payments_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_payments_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_payment_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_payment_put"}, "enable_max_depth"=true}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_payment_delete"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class, properties={
 *     "id": "ASC",
 *     "dateTime",
 *     "createdDateTime",
 *     "numericValue"
 * })
 * @ApiFilter(SearchFilter::class, properties={
 *     "id": "iexact",
 *     "dateTime": "ipartial",
 *     "createdDateTime": "ipartial",
 *     "numericValue": "ipartial"
 * })
 * @ApiFilter(DateFilter::class, properties={"createdDtaeTime", "updatedDateTime", "eMailConfirmationDateTime", "dateTime"})
 * @Searchable({
 *     "id",
 *     "dateTime",
 *     "createdDateTime",
 *     "numericValue"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantPayment extends AbstractPayment
{
    /**
     * Event contact revision (connected to person or organization).
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     inversedBy="participantPayments"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     * @MaxDepth(1)
     */
    protected ?EventParticipant $eventParticipant = null;

    /**
     * @param EventParticipant|null $participant
     * @param int|null              $numericValue
     * @param DateTime|null         $dateTime
     * @param string|null           $type
     * @param string|null           $note
     * @param string|null           $internalNote
     *
     * @param string|null           $externalId
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        ?EventParticipant $participant = null,
        ?int $numericValue = null,
        ?DateTime $dateTime = null,
        ?string $type = null,
        ?string $note = null,
        ?string $internalNote = null,
        ?string $externalId = null
    ) {
        parent::__construct($numericValue, $type, $note, $internalNote, $externalId, $dateTime);
        $this->setEventParticipant($participant);
    }

    public function getEventParticipant(): ?EventParticipant
    {
        return $this->eventParticipant;
    }

    public function setEventParticipant(?EventParticipant $eventParticipant): void
    {
        if ($this->eventParticipant && $eventParticipant !== $this->eventParticipant) {
            $this->eventParticipant->removeParticipantPayment($this);
        }
        if ($eventParticipant && $this->eventParticipant !== $eventParticipant) {
            $this->eventParticipant = $eventParticipant;
            $eventParticipant->addParticipantPayment($this);
        }
    }
}
