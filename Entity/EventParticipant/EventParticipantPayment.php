<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use DateTime;
use Exception;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractPayment;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

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
 *       "normalization_context"={"groups"={"calendar_event_participant_payments_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_payments_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_payment_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_payment_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_payment_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class, properties={
 *     "id": "ASC",
 *     "dateTime",
 *     "createdDateTime",
 *     "numericValue",
 *     "eventParticipant.activeRevision.event.activeRevision.name",
 *     "eventParticipant.activeRevision.event.activeRevision.shortName",
 *     "eventParticipant.activeRevision.event.activeRevision.slug",
 *     "eventParticipant.activeRevision.event.activeRevision.color",
 *     "eventParticipant.activeRevision.contact.id",
 *     "eventParticipant.activeRevision.contact.contactName",
 *     "eventParticipant.activeRevision.contact.contactDetails.content",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 * @ApiFilter(SearchFilter::class, properties={
 *     "id": "iexact",
 *     "dateTime": "ipartial",
 *     "createdDateTime": "ipartial",
 *     "numericValue": "ipartial",
 *     "eventParticipant.activeRevision.event.activeRevision.name": "ipartial",
 *     "eventParticipant.activeRevision.event.activeRevision.shortName": "ipartial",
 *     "eventParticipant.activeRevision.event.activeRevision.slug": "ipartial",
 *     "eventParticipant.activeRevision.event.activeRevision.color": "ipartial",
 *     "eventParticipant.activeRevision.contact.id": "ipartial",
 *     "eventParticipant.activeRevision.contact.contactName": "ipartial",
 *     "eventParticipant.activeRevision.contact.contactDetails.content": "ipartial",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.name": "ipartial",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.shortName": "ipartial",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.slug": "ipartial"
 * })
 * @ApiFilter(DateFilter::class, properties={"createdDtaeTime", "updatedDateTime", "eMailConfirmationDateTime", "dateTime"})
 * @Searchable({
 *     "id",
 *     "dateTime",
 *     "createdDateTime",
 *     "numericValue",
 *     "eventParticipant.activeRevision.event.activeRevision.name",
 *     "eventParticipant.activeRevision.event.activeRevision.shortName",
 *     "eventParticipant.activeRevision.event.activeRevision.slug",
 *     "eventParticipant.activeRevision.event.activeRevision.color",
 *     "eventParticipant.activeRevision.contact.id",
 *     "eventParticipant.activeRevision.contact.contactName",
 *     "eventParticipant.activeRevision.contact.contactDetails.content",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "eventParticipant.activeRevision.eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 */
class EventParticipantPayment extends AbstractPayment
{
    /**
     * Event contact revision (connected to person or organization).
     * @var EventParticipant|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     inversedBy="eventParticipantPayments",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventParticipant;

    /**
     * @param EventParticipant|null $eventParticipant
     * @param int|null              $numericValue
     * @param DateTime|null         $dateTime
     *
     * @param string|null           $type
     * @param string|null           $note
     * @param string|null           $internalNote
     *
     * @throws Exception
     */
    public function __construct(
        ?EventParticipant $eventParticipant = null,
        ?int $numericValue = null,
        ?DateTime $dateTime = null,
        ?string $type = null,
        ?string $note = null,
        ?string $internalNote = null
    ) {
        $this->setEventParticipant($eventParticipant);
        $this->setNumericValue($numericValue);
        $this->setDateTime($dateTime ?? new DateTime());
        $this->setNote($note);
        $this->setInternalNote($internalNote);
        $this->setType($type);
    }

    final public function getEventParticipant(): ?EventParticipant
    {
        return $this->eventParticipant;
    }

    final public function setEventParticipant(?EventParticipant $eventParticipant): void
    {
        if ($this->eventParticipant && $eventParticipant !== $this->eventParticipant) {
            $this->eventParticipant->removeEventParticipantPayment($this);
        }
        if ($eventParticipant && $this->eventParticipant !== $eventParticipant) {
            $this->eventParticipant = $eventParticipant;
            $eventParticipant->addEventParticipantPayment($this);
        }
    }
}
