<?php /** @noinspection MethodShouldBeFinalInspection */

/** @noinspection PhpUnused */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use OswisOrg\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlag;
use OswisOrg\OswisCoreBundle\Entity\Nameable;
use OswisOrg\OswisCoreBundle\Entity\Publicity;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

/**
 * Flag is some specification of EventParticipant. Each flag can adjust price and can be used only once in one participant.
 *
 * @example Type of accommodation, food allergies, time of arrival/departure...
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_flags_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flags_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_flag_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "shortName",
 *     "description",
 *     "note"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantFlag extends AbstractEventFlag
{
    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantFlagType $eventParticipantFlagType = null;

    /**
     * Price adjustment (positive, negative or zero).
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $price = null;

    public function __construct(?Nameable $nameable = null, ?EventParticipantFlagType $participantFlagType = null, ?Publicity $publicity = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setEventParticipantFlagType($participantFlagType);
        $this->setFieldsFromPublicity($publicity);
    }

    public function getPrice(): int
    {
        return $this->price ?? 0;
    }

    public function setPrice(?int $price): void
    {
        $this->price = $price;
    }

    public function getTypeOfType(): ?string
    {
        return $this->getEventParticipantFlagType() ? $this->getEventParticipantFlagType()
            ->getType() : null;
    }

    public function getEventParticipantFlagType(): ?EventParticipantFlagType
    {
        return $this->eventParticipantFlagType;
    }

    public function setEventParticipantFlagType(?EventParticipantFlagType $eventContactFlagType): void
    {
        $this->eventParticipantFlagType = $eventContactFlagType;
    }
}
