<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use InvalidArgumentException;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventCapacity;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventPrice;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventRegistrationRange;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\EntityPublicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TypeTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_type")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_types_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_types_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_type_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_type_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_type_delete"}}
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
class EventParticipantType
{

    use BasicEntityTrait;
    use NameableBasicTrait;
    use EntityPublicTrait;
    use TypeTrait;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType",
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipants;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventPrice",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType",
     *     fetch="EAGER"
     * )
     */
    protected $eventPrices;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventCapacity",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType",
     *     fetch="EAGER"
     * )
     */
    protected $eventCapacities;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRegistrationRange",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType",
     *     fetch="EAGER"
     * )
     */
    protected $eventRegistrationRanges;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantTypeInEventConnection",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType",
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantTypeInEventConnections;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType",
     *     fetch="EAGER"
     * )
     */
    protected $eventParticipantFlagInEventConnections;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null   $type
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?string $type = null
    ) {
        $this->eventParticipants = new ArrayCollection();
        $this->eventPrices = new ArrayCollection();
        $this->eventCapacities = new ArrayCollection();
        $this->eventRegistrationRanges = new ArrayCollection();
        $this->eventParticipantTypeInEventConnections = new ArrayCollection();
        $this->eventParticipantFlagInEventConnections = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            'attendee', // Attendee of event.
            'organizer', // Organization/department/person who organizes event.
            'staff', // Somebody who works (is member of realization team) in event.
            'sponsor', // Somebody (organization) who supports event.
            'guest', // Somebody who performs at the event.
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    /**
     * @param EventParticipantTypeInEventConnection|null $eventParticipantTypeInEventConnection
     */
    final public function addEventParticipantTypeInEventConnection(?EventParticipantTypeInEventConnection $eventParticipantTypeInEventConnection): void
    {
        if ($eventParticipantTypeInEventConnection && !$this->eventParticipantTypeInEventConnections->contains($eventParticipantTypeInEventConnection)) {
            $this->eventParticipantTypeInEventConnections->add($eventParticipantTypeInEventConnection);
            $eventParticipantTypeInEventConnection->setEventParticipantType($this);
        }
    }

    /**
     * @param EventParticipantTypeInEventConnection|null $eventContactRevision
     */
    final public function removeEventParticipantTypeInEventConnection(?EventParticipantTypeInEventConnection $eventContactRevision): void
    {
        if (!$eventContactRevision) {
            return;
        }
        if ($this->eventParticipantTypeInEventConnections->removeElement($eventContactRevision)) {
            $eventContactRevision->setEventParticipantType(null);
        }
    }

    /**
     * @param EventParticipantFlagInEventConnection|null $eventParticipantFlagInEventConnection
     */
    final public function addEventParticipantFlagInEventConnection(
        ?EventParticipantFlagInEventConnection $eventParticipantFlagInEventConnection
    ): void {
        if ($eventParticipantFlagInEventConnection
            && !$this->eventParticipantFlagInEventConnections->contains($eventParticipantFlagInEventConnection)) {
            $this->eventParticipantFlagInEventConnections->add($eventParticipantFlagInEventConnection);
            $eventParticipantFlagInEventConnection->setEventParticipantType($this);
        }
    }

    /**
     * @param EventParticipantFlagInEventConnection|null $eventParticipantFlagInEventConnection
     */
    final public function removeEventParticipantFlagInEventConnection(
        ?EventParticipantFlagInEventConnection $eventParticipantFlagInEventConnection
    ): void {
        if (!$eventParticipantFlagInEventConnection) {
            return;
        }
        if ($this->eventParticipantFlagInEventConnections->removeElement($eventParticipantFlagInEventConnection)) {
            $eventParticipantFlagInEventConnection->setEventParticipantType(null);
        }
    }

    /**
     * @return Collection|null
     */
    final public function getEventParticipantTypeInEventConnections(): ?Collection
    {
        return $this->eventParticipantTypeInEventConnections ?? new ArrayCollection();
    }

    /**
     * @return Collection|null
     */
    final public function getEventParticipantFlagInEventConnections(): ?Collection
    {
        return $this->eventParticipantFlagInEventConnections ?? new ArrayCollection();
    }

    final public function getEventParticipants(): Collection
    {
        return $this->eventParticipants ?? new ArrayCollection();
    }

    final public function addEventParticipant(?EventParticipant $flagConnection): void
    {
        if ($flagConnection && !$this->eventParticipants->contains($flagConnection)) {
            $this->eventParticipants->add($flagConnection);
            $flagConnection->setEventParticipantType($this);
        }
    }

    final public function removeEventParticipant(?EventParticipant $flagConnection): void
    {
        if (!$flagConnection) {
            return;
        }
        if ($this->eventParticipants->removeElement($flagConnection)) {
            $flagConnection->setEventParticipantType(null);
        }
    }

    final public function getEventPrices(): Collection
    {
        return $this->eventPrices ?? new ArrayCollection();
    }

    final public function addEventPrice(?EventPrice $eventPrice): void
    {
        if ($eventPrice && !$this->eventPrices->contains($eventPrice)) {
            $this->eventPrices->add($eventPrice);
            $eventPrice->setEventParticipantType($this);
        }
    }

    final public function removeEventPrice(?EventPrice $eventPrice): void
    {
        if (!$eventPrice) {
            return;
        }
        if ($this->eventPrices->removeElement($eventPrice)) {
            $eventPrice->setEventParticipantType(null);
        }
    }


    final public function getEventCapacities(): Collection
    {
        return $this->eventCapacities ?? new ArrayCollection();
    }

    final public function addEventCapacity(?EventCapacity $eventCapacity): void
    {
        if ($eventCapacity && !$this->eventCapacities->contains($eventCapacity)) {
            $this->eventCapacities->add($eventCapacity);
            $eventCapacity->setEventParticipantType($this);
        }
    }

    final public function removeEventCapacity(?EventCapacity $eventCapacity): void
    {
        if (!$eventCapacity) {
            return;
        }
        if ($this->eventCapacities->removeElement($eventCapacity)) {
            $eventCapacity->setEventParticipantType(null);
        }
    }


    final public function getEventRegistrationRanges(): Collection
    {
        return $this->eventRegistrationRanges ?? new ArrayCollection();
    }

    final public function addEventRegistrationRange(?EventRegistrationRange $eventRegistrationRange): void
    {
        if ($eventRegistrationRange && !$this->eventRegistrationRanges->contains($eventRegistrationRange)) {
            $this->eventRegistrationRanges->add($eventRegistrationRange);
            $eventRegistrationRange->setEventParticipantType($this);
        }
    }

    final public function removeEventRegistrationRange(?EventRegistrationRange $eventRegistrationRange): void
    {
        if (!$eventRegistrationRange) {
            return;
        }
        if ($this->eventRegistrationRanges->removeElement($eventRegistrationRange)) {
            $eventRegistrationRange->setEventParticipantType(null);
        }
    }

}
