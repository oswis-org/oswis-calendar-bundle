<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

class Capacity
{
    public int $baseCapacity = 0;

    public int $fullCapacity = 0;

    public function __construct(?int $baseCapacity = null, ?int $fullCapacity = null)
    {
        $this->setCapacity($baseCapacity, $fullCapacity);
    }

    public function getCapacity(bool $full = false): int {
        return true === $full ? $this->getFullCapacity() : $this->getBaseCapacity();
    }

    public function setCapacity(?int $baseCapacity = null, ?int $fullCapacity = null): void {
        $baseCapacity ??= 0;
        $fullCapacity ??= 0;
        $baseCapacity = 1 > $baseCapacity ? 0 : $baseCapacity;
        $fullCapacity = 1 > $fullCapacity ? 0 : $fullCapacity;
        $fullCapacity = $fullCapacity < $baseCapacity ? $baseCapacity : $fullCapacity;
        $this->setBaseCapacity($baseCapacity);
        $this->setFullCapacity($fullCapacity);
    }

    public function getBaseCapacity(): int
    {
        if ($this->getFullCapacity() < $this->baseCapacity) {
            return $this->getFullCapacity();
        }

        return $this->baseCapacity;
    }

    public function setBaseCapacity(?int $baseCapacity): void
    {
        $baseCapacity ??= 0;
        $this->baseCapacity = 0 > $baseCapacity ? 0 : $baseCapacity;
    }

    public function getFullCapacity(): int
    {
        return $this->fullCapacity;
    }

    public function setFullCapacity(?int $fullCapacity): void
    {
        $fullCapacity ??= 0;
        $this->fullCapacity = 0 > $fullCapacity ? 0 : $fullCapacity;
    }
}
