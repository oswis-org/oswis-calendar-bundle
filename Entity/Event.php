<?php

namespace Zakjakub\OswisCalendarBundle\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DateRangeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(name="calendar_event")
 * @ApiResource()
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "description",
 *     "note"
 * })
 */
class Event
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use DateRangeTrait;

    /**
     * Parent event (if this is not top level event).
     * @var Event|null $parentEvent
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
     *     inversedBy="subEvents",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $parentEvent;

    /**
     * Sub events.
     * @var Collection|null $subEvents
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
     *     mappedBy="parentEvent",
     *     fetch="EAGER"
     * )
     */
    protected $subEvents;

    /**
     * @var Place|null $parentEvent
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisAddressBookBundle\Entity\Place",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected $place;

    /**
     * @var bool
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $registrationRequired;

    /**
     * @var bool
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $registrationsAllowed;

    /**
     * @var int
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $maxCapacity;

    /**
     * Event constructor.
     *
     * @param Nameable|null $nameable
     * @param Event|null    $parentEvent
     * @param Place|null    $place
     * @param bool|null     $registrationRequired
     * @param bool|null     $registrationsAllowed
     * @param int|null      $maxCapacity
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $parentEvent = null,
        ?Place $place = null,
        ?bool $registrationRequired = null,
        ?bool $registrationsAllowed = null,
        ?int $maxCapacity = null
    ) {
        $this->subEvents = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setParentEvent($parentEvent);
        $this->setPlace($place);
        $this->setRegistrationRequired($registrationRequired);
        $this->setRegistrationsAllowed($registrationsAllowed);
        $this->setMaxCapacity($maxCapacity);
    }

    /**
     * @return bool|null
     */
    final public function isRegistrationRequired(): ?bool
    {
        return $this->registrationRequired;
    }

    /**
     * @param bool|null $registrationRequired
     */
    final public function setRegistrationRequired(?bool $registrationRequired): void
    {
        $this->registrationRequired = $registrationRequired;
    }

    /**
     * @return bool|null
     */
    final public function isRegistrationsAllowed(): ?bool
    {
        return $this->registrationsAllowed;
    }

    /**
     * @param bool|null $registrationsAllowed
     */
    final public function setRegistrationsAllowed(?bool $registrationsAllowed): void
    {
        $this->registrationsAllowed = $registrationsAllowed;
    }

    /**
     * @return Collection
     */
    final public function getSubEvents(): Collection
    {
        return $this->subEvents;
    }

    /**
     * @return bool
     */
    final public function isRootEvent(): bool
    {
        return $this->parentEvent ? false : true;
    }

    /**
     * @param Event|null $event
     */
    final public function addSubEvent(?Event $event): void
    {
        if ($event && !$this->subEvents->contains($event)) {
            $this->subEvents->add($event);
            $event->setParentEvent($this);
        }
    }

    /**
     * @param Event|null $event
     */
    final public function removeSubEvent(?Event $event): void
    {
        if (!$event) {
            return;
        }
        if ($this->subEvents->removeElement($event)) {
            $event->setParentEvent(null);
        }
    }

    /**
     * @return Event|null
     */
    final public function getParentEvent(): ?Event
    {
        return $this->parentEvent;
    }

    /**
     * @param Event|null $event
     */
    final public function setParentEvent(?Event $event): void
    {
        if ($this->parentEvent && $event !== $this->parentEvent) {
            $this->parentEvent->removeSubEvent($this);
        }
        $this->parentEvent = $event;
        if ($this->parentEvent) {
            $this->parentEvent->addSubEvent($this);
        }
    }

    /**
     * @return Place|null
     */
    final public function getPlace(): ?Place
    {
        return $this->place;
    }

    /**
     * @param Place|null $event
     */
    final public function setPlace(?Place $event): void
    {
        $this->place = $event;
    }

    /**
     * @return int|null
     */
    final public function getMaxCapacity(): ?int
    {
        return $this->maxCapacity;
    }

    /**
     * @param int|null $maxCapacity
     */
    final public function setMaxCapacity(?int $maxCapacity): void
    {
        $this->maxCapacity = $maxCapacity;
    }
}
