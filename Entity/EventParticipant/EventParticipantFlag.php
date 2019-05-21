<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Zakjakub\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlag;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

/**
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
class EventParticipantFlag extends AbstractEventFlag
{

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagConnection",
     *     mappedBy="eventParticipantFlag",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantFlagConnections;

    /**
     * Event contact flag type.
     * @var EventParticipantFlagType|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType",
     *     inversedBy="eventContactFlagTypes",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $eventParticipantFlagType;

    /**
     * Price adjust (positive, negative or zero).
     * @var int
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $price;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null                 $nameable
     * @param EventParticipantFlagType|null $eventParticipantFlagType
     * @param bool|null                     $publicInIS
     * @param bool|null                     $publicInPortal
     * @param bool|null                     $publicOnWeb
     * @param string|null                   $publicOnWebRoute
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?EventParticipantFlagType $eventParticipantFlagType = null,
        ?bool $publicInIS = null,
        ?bool $publicInPortal = null,
        ?bool $publicOnWeb = null,
        ?string $publicOnWebRoute = null
    ) {
        $this->eventParticipantFlagConnections = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setEventParticipantFlagType($eventParticipantFlagType);
        $this->setPublicInIS($publicInIS);
        $this->setPublicInPortal($publicInPortal);
        $this->setPublicOnWeb($publicOnWeb);
        $this->setPublicOnWebRoute($publicOnWebRoute);
    }

    /**
     * @return int
     */
    final public function getPrice(): int
    {
        return $this->price ?? 0;
    }

    /**
     * @param int $price
     */
    final public function setPrice(?int $price): void
    {
        $this->price = $price;
    }

    final public function getEventParticipantFlagConnections(): Collection
    {
        return $this->eventParticipantFlagConnections;
    }

    final public function addEventParticipantFlagConnection(?EventParticipantFlagConnection $flagConnection): void
    {
        if ($flagConnection && !$this->eventParticipantFlagConnections->contains($flagConnection)) {
            $this->eventParticipantFlagConnections->add($flagConnection);
            $flagConnection->setEventParticipantFlag($this);
        }
    }

    final public function removeEventParticipantFlagConnection(?EventParticipantFlagConnection $flagConnection): void
    {
        if (!$flagConnection) {
            return;
        }
        if ($this->eventParticipantFlagConnections->removeElement($flagConnection)) {
            $flagConnection->setEventParticipantFlag(null);
        }
    }

    final public function getEventParticipantFlagType(): ?EventParticipantFlagType
    {
        return $this->eventParticipantFlagType;
    }

    final public function setEventParticipantFlagType(?EventParticipantFlagType $eventContactFlagType): void
    {
        if ($this->eventParticipantFlagType && $eventContactFlagType !== $this->eventParticipantFlagType) {
            $this->eventParticipantFlagType->removeEventParticipantFlag($this);
        }
        if ($eventContactFlagType && $this->eventParticipantFlagType !== $eventContactFlagType) {
            $this->eventParticipantFlagType = $eventContactFlagType;
            $eventContactFlagType->addEventParticipantFlag($this);
        }
    }

}
