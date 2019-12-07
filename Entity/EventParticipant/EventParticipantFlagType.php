<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlagType;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag_type")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_flag_types_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_types_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_flag_type_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_type_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_type_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "description",
 *     "note"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantFlagType extends AbstractEventFlagType
{

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag",
     *     mappedBy="eventParticipantFlagType",
     *     cascade={"all"}
     * )
     */
    protected ?Collection $eventParticipantFlags = null;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null $nameable
     * @param int|null      $minFlagsAllowed
     * @param int|null      $maxFlagsAllowed
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?int $minFlagsAllowed = null,
        ?int $maxFlagsAllowed = null
    ) {
        $this->eventParticipantFlags = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setMinInEventParticipant($minFlagsAllowed);
        $this->setMaxInEventParticipant($maxFlagsAllowed);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            'food',
            'transport',
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    final public function getEventParticipantFlags(): Collection
    {
        return $this->eventParticipantFlags;
    }

    final public function addEventParticipantFlag(?EventParticipantFlag $eventContactFlag): void
    {
        if ($eventContactFlag && !$this->eventParticipantFlags->contains($eventContactFlag)) {
            $this->eventParticipantFlags->add($eventContactFlag);
            $eventContactFlag->setEventParticipantFlagType($this);
        }
    }

    final public function removeEventParticipantFlag(?EventParticipantFlag $eventContactFlag): void
    {
        if (!$eventContactFlag) {
            return;
        }
        if ($this->eventParticipantFlags->removeElement($eventContactFlag)) {
            $eventContactFlag->setEventParticipantFlagType(null);
        }
    }
}
