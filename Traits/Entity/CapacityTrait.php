<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;

trait CapacityTrait
{
    /**
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $fullCapacity = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $baseCapacity = null;

    public function setCapacity(?Capacity $capacity = null): void
    {
        $this->setBaseCapacity($capacity?->getBaseCapacity());
        $this->setFullCapacity($capacity?->getFullCapacity());
    }

    public function getCapacityInt(bool $full = false): ?int
    {
        return $this->getCapacity()->getCapacity($full);
    }

    public function getCapacity(): Capacity
    {
        return new Capacity($this->baseCapacity, $this->fullCapacity);
    }

    public function getBaseCapacity(): ?int
    {
        return $this->getCapacity()->getBaseCapacity();
    }

    public function setBaseCapacity(?int $baseCapacity): void
    {
        $baseCapacity ??= 0;
        $this->baseCapacity = 0 > $baseCapacity ? 0 : $baseCapacity;
    }

    public function getFullCapacity(): ?int
    {
        return $this->getCapacity()->getFullCapacity();
    }

    public function setFullCapacity(?int $fullCapacity): void
    {
        if (null === $fullCapacity) {
            $this->fullCapacity = null;

            return;
        }
        $this->fullCapacity = 0 > $fullCapacity ? 0 : $fullCapacity;
    }
}