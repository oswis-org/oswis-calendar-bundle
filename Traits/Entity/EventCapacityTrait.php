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

    public function getMaxCapacity(): ?int
    {
        return null === $this->getCapacity() ? null : (0 + $this->getCapacity() + $this->getCapacityOverflowLimit());
    }

    public function getCapacity(bool $withOverflow = false): ?int
    {
        $baseCapacity = $this->capacity;
        $overflowLimit = $this->getCapacityOverflowLimit();
        if (null === $baseCapacity || ($withOverflow && null === $overflowLimit)) {
            return null;
        }

        return true === $withOverflow ? $baseCapacity + $overflowLimit : $baseCapacity;
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

    public function isCapacityUnlimited(): bool
    {
        return null === $this->getCapacity();
    }

    public function isCapacityOverflowUnlimited(): bool
    {
        return null === $this->getCapacityOverflowLimit();
    }

}