<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlag;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_flag")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_flags_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_flags_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_flag_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_flag_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_flag_delete"}}
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
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventFlag extends AbstractEventFlag
{

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventFlagConnection",
     *     mappedBy="eventFlag",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $eventFlagConnections = null;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null $nameable
     */
    public function __construct(
        ?Nameable $nameable = null
    ) {
        $this->eventFlagConnections = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    final public function getEventFlagConnections(): Collection
    {
        return $this->eventFlagConnections;
    }

    final public function addEventFlagConnection(?EventFlagConnection $flagConnection): void
    {
        if ($flagConnection && !$this->eventFlagConnections->contains($flagConnection)) {
            $this->eventFlagConnections->add($flagConnection);
            $flagConnection->setEventFlag($this);
        }
    }

    final public function removeEventFlagConnection(?EventFlagConnection $flagConnection): void
    {
        if (!$flagConnection) {
            return;
        }
        if ($this->eventFlagConnections->removeElement($flagConnection)) {
            $flagConnection->setEventFlag(null);
        }
    }
}
