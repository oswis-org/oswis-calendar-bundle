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
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_organizer_flag_connection")
 * @ApiResource(
 *   attributes={
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_organizer_flag_connections_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_organizer_flag_connections_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_organizer_flag_connection_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_organizer_flag_connection_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_organizer_flag_connection_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "flag.name",
 *     "flag.description",
 *     "flag.note",
 *     "textValue"
 * })
 */
class EventOrganizerFlagConnection
{
    use BasicEntityTrait;
    use TextValueTrait;

    /**
     * Dummy "selected" property for forms.
     * @var bool
     */
    public $selected;

    /**
     * Flag.
     * @var EventOrganizerFlag|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventOrganizerFlag",
     *     inversedBy="eventOrganizerFlagConnections",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $flag;

    /**
     * @var EventOrganizer|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventOrganizer",
     *     inversedBy="flags",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventOrganizer;

    public function __construct(
        ?EventOrganizerFlag $flag = null,
        ?EventOrganizer $employer = null
    ) {
        $this->setFlag($flag);
        $this->setEventOrganizer($employer);
    }

    final public function getEventOrganizer(): ?EventOrganizer
    {
        return $this->eventOrganizer;
    }

    final public function setEventOrganizer(?EventOrganizer $eventOrganizer): void
    {
        if ($this->eventOrganizer && $eventOrganizer !== $this->eventOrganizer) {
            $this->eventOrganizer->removeFlag($this);
        }
        $this->eventOrganizer = $eventOrganizer;
        if ($eventOrganizer && $this->eventOrganizer !== $eventOrganizer) {
            $eventOrganizer->addFlag($this);
        }
    }

    final public function getFlag(): ?EventOrganizerFlag
    {
        return $this->flag;
    }

    final public function setFlag(?EventOrganizerFlag $employerFlag): void
    {
        if ($this->flag && $employerFlag !== $this->flag) {
            $this->flag->removeEventOrganizerFlagConnection($this);
        }
        $this->flag = $employerFlag;
        if ($employerFlag && $this->flag !== $employerFlag) {
            $employerFlag->addEventOrganizerFlagConnection($this);
        }
    }

}
