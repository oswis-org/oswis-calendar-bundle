<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Zakjakub\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlag;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

/**
 * Flag of participant of some event.
 *
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_flags_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flags_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_flag_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_delete"}}
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
class EventParticipantFlag extends AbstractEventFlag
{
    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagNewConnection",
     *     mappedBy="eventParticipantFlag",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $eventParticipantFlagNewConnections = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection",
     *     mappedBy="eventParticipantFlag",
     *     cascade={"all"}
     * )
     */
    protected ?Collection $eventParticipantFlagInEventConnections = null;

    /**
     * Event contact flag type.
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantFlagType $eventParticipantFlagType = null;

    /**
     * Price adjust (positive, negative or zero).
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $price = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?EventParticipantFlagType $eventParticipantFlagType = null,
        ?bool $publicInIS = null,
        ?bool $publicInPortal = null,
        ?bool $publicOnWeb = null,
        ?string $publicOnWebRoute = null
    ) {
        $this->eventParticipantFlagNewConnections = new ArrayCollection();
        $this->eventParticipantFlagInEventConnections = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setEventParticipantFlagType($eventParticipantFlagType);
        $this->setPublicInIS($publicInIS);
        $this->setPublicInPortal($publicInPortal);
        $this->setPublicOnWeb($publicOnWeb);
        $this->setPublicOnWebRoute($publicOnWebRoute);
    }

    final public function getPrice(): int
    {
        return $this->price ?? 0;
    }

    final public function setPrice(?int $price): void
    {
        $this->price = $price;
    }

    final public function getEventParticipantFlagNewConnections(): Collection
    {
        return $this->eventParticipantFlagNewConnections;
    }

    final public function addEventParticipantFlagConnection(?EventParticipantFlagNewConnection $flagConnection): void
    {
        if ($flagConnection && !$this->eventParticipantFlagNewConnections->contains($flagConnection)) {
            $this->eventParticipantFlagNewConnections->add($flagConnection);
            $flagConnection->setEventParticipantFlag($this);
        }
    }

    final public function removeEventParticipantFlagConnection(?EventParticipantFlagNewConnection $flagConnection): void
    {
        if ($flagConnection && $this->eventParticipantFlagNewConnections->removeElement($flagConnection)) {
            $flagConnection->setEventParticipantFlag(null);
        }
    }

    final public function getEventParticipantFlagInEventConnections(): Collection
    {
        return $this->eventParticipantFlagInEventConnections ?? new ArrayCollection();
    }

    final public function addEventParticipantFlagInEventConnection(?EventParticipantFlagInEventConnection $eventParticipantFlagInEventConnection): void
    {
        if ($eventParticipantFlagInEventConnection && !$this->eventParticipantFlagInEventConnections->contains($eventParticipantFlagInEventConnection)) {
            $this->eventParticipantFlagInEventConnections->add($eventParticipantFlagInEventConnection);
            $eventParticipantFlagInEventConnection->setEventParticipantFlag($this);
        }
    }

    final public function removeEventParticipantFlagInEventConnection(?EventParticipantFlagInEventConnection $eventParticipantFlagInEventConnection): void
    {
        if ($eventParticipantFlagInEventConnection && $this->eventParticipantFlagInEventConnections->removeElement($eventParticipantFlagInEventConnection)) {
            $eventParticipantFlagInEventConnection->setEventParticipantFlag(null);
        }
    }

    final public function getEventParticipantFlagType(): ?EventParticipantFlagType
    {
        return $this->eventParticipantFlagType;
    }

    final public function setEventParticipantFlagType(?EventParticipantFlagType $eventContactFlagType): void
    {
        $this->eventParticipantFlagType = $eventContactFlagType;
    }
}
