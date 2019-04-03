<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_attendee_flag_connection")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_attendee_flag_connections_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_flag_connections_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_attendee_flag_connection_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_flag_connection_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_attendee_flag_connection_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "description",
 *     "note",
 *     "textValue",
 *     "flag.name",
 *     "flag.description",
 *     "flag.note"
 * })
 */
class EventAttendeeFlagConnection
{
    use BasicEntityTrait;
    use TextValueTrait;

    /**
     * @var bool
     */
    public $selected;

    /**
     * Flag.
     * @var EventAttendeeFlag|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag",
     *     inversedBy="eventAttendeeFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $flag;

    /**
     * Attendee in event.
     * @var EventAttendee|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventAttendee",
     *     inversedBy="eventAttendeeFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventAttendee;

    /**
     * FlagInEmployerInEvent constructor.
     *
     * @param EventAttendeeFlag|null $flag
     * @param EventAttendee|null     $eventAttendee
     */
    public function __construct(
        ?EventAttendeeFlag $flag = null,
        ?EventAttendee $eventAttendee = null
    ) {
        $this->flag = $flag;
        $this->eventAttendee = $eventAttendee;
    }

    final public function getEventAttendee(): ?EventAttendee
    {
        return $this->eventAttendee;
    }

    final public function setEventAttendee(?EventAttendee $eventAttendee): void
    {
        if ($this->eventAttendee && $eventAttendee !== $this->eventAttendee) {
            $this->eventAttendee->removeEventAttendeeFlagConnection($this);
        }
        if ($eventAttendee && $this->eventAttendee !== $eventAttendee) {
            $this->eventAttendee = $eventAttendee;
            $eventAttendee->addEventAttendeeFlagConnection($this);
        }
    }

    final public function getFlag(): ?EventAttendeeFlag
    {
        return $this->flag;
    }

    final public function setFlag(?EventAttendeeFlag $flag): void
    {
        if ($this->flag && $flag !== $this->flag) {
            $this->flag->removeEventAttendeeFlagConnection($this);
        }
        if ($flag && $this->flag !== $flag) {
            $this->flag = $flag;
            $flag->addEventAttendeeFlagConnection($this);
        }
    }
}
