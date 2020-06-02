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
    protected ?int $maxCapacity = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $capacity = null;

    public function getMaxCapacity(): ?int
    {
        return $this->maxCapacity;
    }

    public function getCapacity(bool $max = false): ?int
    {
        $baseCapacity = $this->capacity;
        $maxCapacity = $this->getMaxCapacity();
        if (null === $baseCapacity || (true === $max && null === $maxCapacity)) {
            return null;
        }

        return true === $max ? $maxCapacity : $baseCapacity;
    }

    public function setCapacity(?int $capacity): void
    {
        $this->capacity = $capacity;
        if ($this->getCapacity(true) < $this->capacity) {
            $this->setMaxCapacity($this->capacity);
        }
    }

    public function setMaxCapacity(?int $maxCapacity): void
    {
        $this->maxCapacity = $maxCapacity;
    }

    public function setEventCapacity(?EventCapacity $eventCapacity = null): void
    {
        if (null !== $eventCapacity) {
            $this->setCapacity($eventCapacity->capacity);
            $this->setMaxCapacity($eventCapacity->maxCapacity);
        }
    }

    public function getEventCapacity(): EventCapacity
    {
        return new EventCapacity($this->getCapacity(), $this->getMaxCapacity());
    }

    public function isCapacityUnlimited(bool $max = false): bool
    {
        return null === $this->getCapacity($max);
    }

    public function isMaxCapacityUnlimited(): bool
    {
        return null === $this->getMaxCapacity();
    }

}