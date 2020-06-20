<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;

trait CapacityTrait
{
    /**
     * Capacity including overflow.
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=false)
     */
    protected int $fullCapacity = 0;

    /**
     * Capacity.
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=false)
     */
    protected int $baseCapacity = 0;

    public function setCapacity(?Capacity $capacity = null): void
    {
        $this->setBaseCapacity($capacity ? $capacity->getBaseCapacity() : null);
        $this->setFullCapacity($capacity ? $capacity->getFullCapacity() : null);
    }

    public function getCapacityInt(bool $full = false): int
    {
        return $this->getCapacity()->getCapacity($full);
    }

    public function getCapacity(): Capacity
    {
        return new Capacity($this->getBaseCapacity(), $this->getFullCapacity());
    }

    public function getBaseCapacity(): int
    {
        return $this->getCapacity()->getBaseCapacity();
    }

    public function setBaseCapacity(?int $baseCapacity): void
    {
        $baseCapacity ??= 0;
        $this->baseCapacity = 0 > $baseCapacity ? 0 : $baseCapacity;
    }

    public function getFullCapacity(): int
    {
        return $this->getCapacity()->getFullCapacity();
    }

    public function setFullCapacity(?int $fullCapacity): void
    {
        $fullCapacity ??= 0;
        $this->fullCapacity = 0 > $fullCapacity ? 0 : $fullCapacity;
    }
}