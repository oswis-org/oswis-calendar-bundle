<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

class FlagAmountRange
{
    public int $min = 0;

    public ?int $max = null;

    public function __construct(?int $min = null, ?int $max = null)
    {
        $this->setFlagAmountRange($min, $max);
    }

    public function setFlagAmountRange(?int $min = null, ?int $max = null): void
    {
        $min = (null === $min || 0 > $min) ? 0 : $min;
        $max = (null !== $max && 0 > $max) ? 0 : $max;
        $min = null !== $max && $max < $min ? $max : $min;
        $this->setMin($min);
        $this->setMax($max);
    }

    public function getMin(): int
    {
        return $this->getMax() < $this->min ? $this->getMax() : $this->min;
    }

    public function setMin(?int $min): void
    {
        $min ??= 0;
        $this->min = 0 > $min ? 0 : $min;
    }

    public function getMax(): ?int
    {
        return $this->max;
    }

    public function setMax(?int $max): void
    {
        $this->max = (null !== $max && 0 > $max) ? 0 : $max;
    }
}
