<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

class CapacityUsage
{
    public int $baseUsage = 0;

    public int $fullUsage = 0;

    public function __construct(?int $baseUsage = null, ?int $fullUsage = null)
    {
        $this->setUsage($baseUsage, $fullUsage);
    }

    public function setUsage(?int $baseUsage = null, ?int $fullUsage = null): void
    {
        $baseUsage ??= 0;
        $fullUsage ??= 0;
        $baseUsage = 1 > $baseUsage ? 0 : $baseUsage;
        $fullUsage = 1 > $fullUsage ? 0 : $fullUsage;
        $fullUsage = max($fullUsage, $baseUsage);
        $this->setBaseUsage($baseUsage);
        $this->setFullUsage($fullUsage);
    }

    public function getUsage(bool $full = false): int
    {
        return true === $full ? $this->getFullUsage() : $this->getBaseUsage();
    }

    public function getFullUsage(): int
    {
        return $this->fullUsage;
    }

    public function setFullUsage(?int $fullUsage): void
    {
        $fullUsage ??= 0;
        $this->fullUsage = max(0, $fullUsage);
    }

    public function getBaseUsage(): int
    {
        if ($this->getFullUsage() < $this->baseUsage) {
            return $this->getFullUsage();
        }

        return $this->baseUsage;
    }

    public function setBaseUsage(?int $baseUsage): void
    {
        $baseUsage ??= 0;
        $this->baseUsage = max(0, $baseUsage);
    }
}
