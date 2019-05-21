<?php

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
 * Type of registration's flag.
 *
 * Type of flag used in "registrations" (event contacts).
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_attendee_flag")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_attendee_flags_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_flags_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_attendee_flag_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_flag_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_flag_delete"}}
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
 */
class EventParticipantFlagType extends AbstractEventFlagType
{

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="EventParticipantFlag",
     *     mappedBy="eventParticipantFlagType",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantFlags;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null $nameable
     */
    public function __construct(
        ?Nameable $nameable = null
    ) {
        $this->eventParticipantFlags = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
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
