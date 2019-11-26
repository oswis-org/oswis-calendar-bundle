<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NumericValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_capacity")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventCapacity
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use NumericValueTrait;

    /**
     * Event that is affected by this capacity.
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="eventCapacities"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event;

    /**
     * Type of participants allowed by this capacity limit.
     * @var EventParticipantType|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     inversedBy="eventCapacities"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantType $eventParticipantType;

    /**
     * Allow participants overflow (manually by manager).
     * @var int|null
     * @Doctrine\ORM\Mapping\Column(type="integer")
     */
    protected ?int $overflowAllowedAmount;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null             $nameable
     * @param Event|null                $event
     * @param EventParticipantType|null $eventParticipantType
     * @param int|null                  $numericValue
     * @param int|null                  $overflowAllowedAmount
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null,
        ?EventParticipantType $eventParticipantType = null,
        ?int $numericValue = null,
        ?int $overflowAllowedAmount = null
    ) {
        $this->setEvent($event);
        $this->setEventParticipantType($eventParticipantType);
        $this->setNumericValue($numericValue);
        $this->setFieldsFromNameable($nameable);
        $this->setOverflowAllowedAmount($overflowAllowedAmount);
    }

    /**
     * @return int|null
     */
    final public function getOverflowAllowedAmount(): int
    {
        return $this->overflowAllowedAmount ?? 0;
    }

    /**
     * @param int|null $overflowAllowedAmount
     */
    final public function setOverflowAllowedAmount(?int $overflowAllowedAmount): void
    {
        $this->overflowAllowedAmount = $overflowAllowedAmount ?? 0;
    }

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventCapacity($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventCapacity($this);
        }
    }

    final public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    final public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        if ($this->eventParticipantType && $eventParticipantType !== $this->eventParticipantType) {
            $this->eventParticipantType->removeEventCapacity($this);
        }
        if ($eventParticipantType && $this->eventParticipantType !== $eventParticipantType) {
            $this->eventParticipantType = $eventParticipantType;
            $eventParticipantType->addEventCapacity($this);
        }
    }
}
