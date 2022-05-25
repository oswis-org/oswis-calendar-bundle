<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use Doctrine\ORM\Mapping\Column;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CapacityUsage;

trait CapacityUsageTrait
{
    /** Usage including overflow. */
    #[Column(type: 'integer', nullable: false)]
    protected int $fullUsage = 0;

    /** Usage. */
    #[Column(type: 'integer', nullable: false)]
    protected int $baseUsage = 0;

    public function setUsage(?CapacityUsage $usage = null): void
    {
        $this->setBaseUsage($usage?->getBaseUsage());
        $this->setFullUsage($usage?->getFullUsage());
    }

    public function getBaseUsage(): int
    {
        return $this->getUsage()->getBaseUsage();
    }

    public function setBaseUsage(?int $baseUsage): void
    {
        $baseUsage ??= 0;
        $this->baseUsage = max(0, $baseUsage);
    }

    public function getUsage(): CapacityUsage
    {
        return new CapacityUsage($this->baseUsage, $this->fullUsage);
    }

    public function getFullUsage(): int
    {
        return $this->getUsage()->getFullUsage();
    }

    public function setFullUsage(?int $fullUsage): void
    {
        $fullUsage ??= 0;
        $this->fullUsage = max(0, $fullUsage);
    }

    public function getUsageInt(bool $full = false): int
    {
        return $this->getUsage()->getUsage($full);
    }
}
