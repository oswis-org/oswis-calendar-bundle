<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_attendee_flag")
 * @ApiResource(
 *   attributes={
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
class EventAttendeeFlag
{
    use BasicEntityTrait;
    use NameableBasicTrait;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlagConnection",
     *     mappedBy="flag",
     *     fetch="EAGER"
     * )
     */
    protected $eventAttendeeFlagConnections;

    /**
     * @var bool
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $public;

    /**
     * @var bool
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $valueAllowed;

    /**
     * @var string
     * @ORM\Column(type="string", nullable=true)
     */
    protected $valueLabel;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null $nameable
     * @param bool|null     $public
     * @param bool|null     $valueAllowed
     * @param string|null   $valueLabel
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?bool $public = null,
        ?bool $valueAllowed = null,
        ?string $valueLabel = null
    ) {
        $this->eventAttendeeFlagConnections = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setPublic($public);
        $this->setValueAllowed($valueAllowed);
        $this->setValueLabel($valueLabel);
    }

    /**
     * @return string
     */
    final public function getValueLabel(): ?string
    {
        return $this->valueLabel;
    }

    /**
     * @param string $valueLabel
     */
    final public function setValueLabel(?string $valueLabel): void
    {
        $this->valueLabel = $valueLabel;
    }

    /**
     * @return bool
     */
    final public function isValueAllowed(): bool
    {
        return $this->valueAllowed ?? false;
    }

    /**
     * @param bool $valueAllowed
     */
    final public function setValueAllowed(?bool $valueAllowed): void
    {
        $this->valueAllowed = $valueAllowed ?? false;
    }

    /**
     * @return bool
     */
    final public function isPublic(): bool
    {
        return $this->public ?? false;
    }

    /**
     * @param bool $public
     */
    final public function setPublic(?bool $public): void
    {
        $this->public = $public ?? false;
    }

    final public function getEventAttendeeFlagConnections(): Collection
    {
        return $this->eventAttendeeFlagConnections;
    }

    final public function addEventAttendeeFlagConnection(?EventAttendeeFlagConnection $flagConnection): void
    {
        if ($flagConnection && !$this->eventAttendeeFlagConnections->contains($flagConnection)) {
            $this->eventAttendeeFlagConnections->add($flagConnection);
            $flagConnection->setFlag($this);
        }
    }

    final public function removeEventAttendeeFlagConnection(?EventAttendeeFlagConnection $flagConnection): void
    {
        if (!$flagConnection) {
            return;
        }
        if ($this->eventAttendeeFlagConnections->removeElement($flagConnection)) {
            $flagConnection->setFlag(null);
        }
    }
}
