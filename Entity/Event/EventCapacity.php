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
     * Type of participants allowed by this capacity limit.
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipantType $eventParticipantType = null;

    /**
     * Allow participants overflow (manually by manager).
     * @Doctrine\ORM\Mapping\Column(type="integer")
     */
    protected ?int $overflowAllowedAmount = null;

    public function __construct(?Nameable $nameable = null, ?EventParticipantType $eventParticipantType = null, ?int $numericValue = null, ?int $overflowAllowedAmount = null)
    {
        $this->setEventParticipantType($eventParticipantType);
        $this->setNumericValue($numericValue);
        $this->setFieldsFromNameable($nameable);
        $this->setOverflowAllowedAmount($overflowAllowedAmount);
    }

    final public function getOverflowAllowedAmount(): int
    {
        return $this->overflowAllowedAmount ?? 0;
    }

    final public function setOverflowAllowedAmount(?int $overflowAllowedAmount): void
    {
        $this->overflowAllowedAmount = $overflowAllowedAmount ?? 0;
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
