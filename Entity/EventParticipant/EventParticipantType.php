<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
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
use function in_array;

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
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantType
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use EntityPublicTrait;
    use TypeTrait;

    public const TYPE_ATTENDEE = 'attendee';
    public const TYPE_ORGANIZER = 'organizer';
    public const TYPE_STAFF = 'staff';
    public const TYPE_SPONSOR = 'sponsor';
    public const TYPE_GUEST = 'guest';
    public const TYPE_MANAGER = 'manager';
    public const MANAGEMENT_TYPES = [self::TYPE_MANAGER];

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
     *     mappedBy="eventParticipantType"
     * )
     */
    protected $eventPrices;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventCapacity",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType"
     * )
     */
    protected $eventCapacities;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventRegistrationRange",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType"
     * )
     */
    protected $eventRegistrationRanges;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantTypeInEventConnection",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType"
     * )
     */
    protected $eventParticipantTypeInEventConnections;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection",
     *     cascade={"all"},
     *     mappedBy="eventParticipantType"
     * )
     */
    protected $eventParticipantFlagInEventConnections;

    /**
     * Send formal or informal e-mails?
     * @var bool|null
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $formal;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null   $type
     *
     * @param bool|null     $formal
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?string $type = null,
        ?bool $formal = true
    ) {
        $this->eventParticipants = new ArrayCollection();
        $this->eventPrices = new ArrayCollection();
        $this->eventCapacities = new ArrayCollection();
        $this->eventRegistrationRanges = new ArrayCollection();
        $this->eventParticipantTypeInEventConnections = new ArrayCollection();
        $this->eventParticipantFlagInEventConnections = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
        $this->setFormal($formal);
    }

    /**
     * @param bool $formal
     */
    final public function setFormal(?bool $formal): void
    {
        $this->formal = $formal ?? false;
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            self::TYPE_ATTENDEE, // Attendee of event.
            self::TYPE_ORGANIZER, // Organization/department/person who organizes event.
            self::TYPE_STAFF, // Somebody who works (is member of realization team) in event.
            self::TYPE_SPONSOR, // Somebody (organization) who supports event.
            self::TYPE_GUEST, // Somebody who performs at the event.
            self::TYPE_MANAGER, // Somebody who manages the event.
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    /**
     * @return bool
     */
    final public function isFormal(): bool
    {
        return $this->formal ?? false;
    }

    final public function isManager(): bool
    {
        return in_array($this->getType(), self::MANAGEMENT_TYPES, true);
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
        if ($eventContactRevision && $this->eventParticipantTypeInEventConnections->removeElement($eventContactRevision)) {
            $eventContactRevision->setEventParticipantType(null);
        }
    }

    /**
     * @param EventParticipantFlagInEventConnection|null $eventParticipantFlagInEventConnection
     */
    final public function addEventParticipantFlagInEventConnection(?EventParticipantFlagInEventConnection $eventParticipantFlagInEventConnection): void
    {
        if ($eventParticipantFlagInEventConnection && !$this->eventParticipantFlagInEventConnections->contains($eventParticipantFlagInEventConnection)) {
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
        if ($eventParticipantFlagInEventConnection && $this->eventParticipantFlagInEventConnections->removeElement($eventParticipantFlagInEventConnection)) {
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
