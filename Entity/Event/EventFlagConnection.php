<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

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
class EventFlagConnection
{
    use BasicEntityTrait;
    use TextValueTrait;

    /**
     * @var bool
     */
    public $selected;

    /**
     * Event flag.
     * @var EventFlag|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventFlag",
     *     inversedBy="eventFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventFlag;

    /**
     * Event.
     * @var EventRevision|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRevision",
     *     inversedBy="eventFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventRevision;

    /**
     * FlagInEmployerInEvent constructor.
     *
     * @param EventFlag|null     $eventContactFlag
     * @param EventRevision|null $eventRevision
     */
    public function __construct(
        ?EventFlag $eventContactFlag = null,
        ?EventRevision $eventRevision = null
    ) {
        $this->eventFlag = $eventContactFlag;
        $this->eventRevision = $eventRevision;
    }

    final public function getEventRevision(): ?EventRevision
    {
        return $this->eventRevision;
    }

    final public function setEventRevision(?EventRevision $eventRevision): void
    {
        if ($this->eventRevision && $eventRevision !== $this->eventRevision) {
            $this->eventRevision->removeEventFlagConnection($this);
        }
        if ($eventRevision && $this->eventRevision !== $eventRevision) {
            $this->eventRevision = $eventRevision;
            $eventRevision->addEventFlagConnection($this);
        }
    }

    final public function getEventFlag(): ?EventFlag
    {
        return $this->eventFlag;
    }

    final public function setEventFlag(?EventFlag $eventFlag): void
    {
        if ($this->eventFlag && $eventFlag !== $this->eventFlag) {
            $this->eventFlag->removeEventFlagConnection($this);
        }
        if ($eventFlag && $this->eventFlag !== $eventFlag) {
            $this->eventFlag = $eventFlag;
            $eventFlag->addEventFlagConnection($this);
        }
    }
}
