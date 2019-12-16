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
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantType $eventParticipantType = null;

    public function __construct(?Nameable $nameable = null, ?EventParticipantType $eventParticipantType = null, ?int $numericValue = null, ?int $taxRate = null, ?int $depositValue = null)
    {
        $this->setEventParticipantType($eventParticipantType);
        $this->setNumericValue($numericValue);
        $this->setTaxRate($taxRate);
        $this->setFieldsFromNameable($nameable);
        $this->setDepositValue($depositValue);
    }

    final public function isApplicableForEventParticipantType(EventParticipantType $eventParticipantType): bool
    {
        return null !== $eventParticipantType && $this->getEventParticipantType() ? $eventParticipantType->getId() === $this->getEventParticipantType()->getId() : false;
    }

    final public function getEventParticipantType(): ?EventParticipantType
    {
        return $this->eventParticipantType;
    }

    final public function setEventParticipantType(?EventParticipantType $eventParticipantType): void
    {
        $this->eventParticipantType = $eventParticipantType;
    }
}
