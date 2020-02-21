<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Traits\Entity;

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
}