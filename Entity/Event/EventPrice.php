<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DepositValueTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NumericValueTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TaxRateTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_price")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventPrice
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use NumericValueTrait;
    use TaxRateTrait;
    use DepositValueTrait;

    // TODO: Dates missing!

    /**
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="eventPrices"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @var EventParticipantType|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     inversedBy="eventPrices"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantType $eventParticipantType = null;

    /**
     * @param Nameable|null             $nameable
     * @param Event|null                $event
     * @param EventParticipantType|null $eventParticipantType
     * @param int|null                  $numericValue
     * @param int|null                  $taxRate
     * @param int|null                  $depositValue
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null,
        ?EventParticipantType $eventParticipantType = null,
        ?int $numericValue = null,
        ?int $taxRate = null,
        ?int $depositValue = null
    ) {
        $this->setEvent($event);
        $this->setEventParticipantType($eventParticipantType);
        $this->setNumericValue($numericValue);
        $this->setTaxRate($taxRate);
        $this->setFieldsFromNameable($nameable);
        $this->setDepositValue($depositValue);
    }

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventPrice($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventPrice($this);
        }
    }

    final public function isApplicableForEventParticipantType(EventParticipantType $eventParticipantType): bool
    {
        if (!$eventParticipantType || !$this->getEventParticipantType()) {
            return false;
        }

        return $eventParticipantType->getId() === $this->getEventParticipantType()->getId();
    }

    final public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    final public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        if ($this->eventParticipantType && $eventParticipantType !== $this->eventParticipantType) {
            $this->eventParticipantType->removeEventPrice($this);
        }
        if ($eventParticipantType && $this->eventParticipantType !== $eventParticipantType) {
            $this->eventParticipantType = $eventParticipantType;
            $eventParticipantType->addEventPrice($this);
        }
    }
}
