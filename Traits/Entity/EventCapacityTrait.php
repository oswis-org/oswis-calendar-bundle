<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\EventCapacity;

trait EventCapacityTrait
{
    /**
     * Allowed capacity overflow (can be used only by managers and admins).
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $capacityOverflowLimit = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $capacity = null;

    public function getMaxCapacity(): int
    {
        return 0 + $this->getCapacity() + $this->getCapacityOverflowLimit();
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(?int $capacity): void
    {
        $this->capacity = $capacity;
    }

    public function getCapacityOverflowLimit(): ?int
    {
        return $this->capacityOverflowLimit;
    }

    public function setCapacityOverflowLimit(?int $capacityOverflowLimit): void
    {
        $this->capacityOverflowLimit = $capacityOverflowLimit;
    }

    public function setEventCapacity(?EventCapacity $eventCapacity = null): void
    {
        if (null !== $eventCapacity) {
            $this->setCapacity($eventCapacity->capacity);
            $this->setCapacityOverflowLimit($eventCapacity->capacityOverflowLimit);
        }
    }

    public function getEventCapacity(): EventCapacity
    {
        return new EventCapacity($this->getCapacity(), $this->getCapacityOverflowLimit());
    }

}