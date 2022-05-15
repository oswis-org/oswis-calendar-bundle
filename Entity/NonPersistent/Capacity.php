<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

class Capacity
{
    public ?int $baseCapacity = null;

    public ?int $fullCapacity = null;

    public function __construct(?int $baseCapacity = null, ?int $fullCapacity = null)
    {
        $this->setCapacity($baseCapacity, $fullCapacity);
    }

    public function setCapacity(?int $baseCapacity = null, ?int $fullCapacity = null): void
    {
        if (null === $baseCapacity) {
            $this->baseCapacity = null;
            $this->fullCapacity = null;

            return;
        }
        if (null === $fullCapacity) {
            $this->baseCapacity = $baseCapacity;
            $this->fullCapacity = null;

            return;
        }
        $baseCapacity = 1 > $baseCapacity ? 0 : $baseCapacity;
        $fullCapacity = 1 > $fullCapacity ? 0 : $fullCapacity;
        $fullCapacity = max($fullCapacity, $baseCapacity);
        $this->setBaseCapacity($baseCapacity);
        $this->setFullCapacity($fullCapacity);
    }

    public function getCapacity(bool $full = false): ?int
    {
        return true === $full ? $this->getFullCapacity() : $this->getBaseCapacity();
    }

    public function getFullCapacity(): ?int
    {
        return $this->fullCapacity;
    }

    public function setFullCapacity(?int $fullCapacity): void
    {
        if (null === $fullCapacity) {
            $this->fullCapacity = null;

            return;
        }
        $this->fullCapacity = max(0, $fullCapacity);
    }

    public function getBaseCapacity(): ?int
    {
        if (null === $this->baseCapacity) {
            return null;
        }

        return $this->getFullCapacity() < $this->baseCapacity ? $this->getFullCapacity() : $this->baseCapacity;
    }

    public function setBaseCapacity(?int $baseCapacity): void
    {
        if (null === $baseCapacity) {
            $this->baseCapacity = null;
            $this->fullCapacity = null;

            return;
        }
        $this->baseCapacity = max(0, $baseCapacity);
    }
}
