<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagAmountRange;

trait FlagAmountRangeTrait
{
    /**
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=false)
     */
    protected int $min = 0;

    /**
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $max = 0;

    public function setFlagAmountRange(?FlagAmountRange $flagAmountRange = null): void
    {
        $this->setMin($flagAmountRange ? $flagAmountRange->getMin() : null);
        $this->setMax($flagAmountRange ? $flagAmountRange->getMax() : null);
    }

    public function getFlagAmountRange(): FlagAmountRange
    {
        return new FlagAmountRange($this->getMin(), $this->getMax());
    }

    public function getMax(): ?int
    {
        return $this->getFlagAmountRange()->getMax();
    }

    public function setMax(?int $max): void
    {
        $this->max = null !== $max && 0 > $max ? 0 : $max;
    }

    public function getMin(): int
    {
        return $this->getFlagAmountRange()->getMin();
    }

    public function setMin(?int $min): void
    {
        $min ??= 0;
        $this->min = 0 > $min ? 0 : $min;
    }
}