<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagAmountRange;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;

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

    /**
     * @param  int  $count
     *
     * @throws FlagOutOfRangeException
     */
    public function checkInRange(int $count): void
    {
        $name = $this->getName();
        $min = $this->getMin();
        $max = $this->getMax();
        $actually = "(nyní je jich $count)";
        if ($count < $min) {
            throw new FlagOutOfRangeException("Přihláška musí obsahovat alespoň $min příznaků '$name' $actually.");
        }
        if (null !== $max && $count > $max) {
            throw new FlagOutOfRangeException("Přihláška může obsahovat maximálně $max příznaků '$name' $actually.");
        }
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

    public function getFlagAmountRange(): FlagAmountRange
    {
        return new FlagAmountRange($this->min, $this->max);
    }

    public function getMax(): ?int
    {
        return $this->getFlagAmountRange()->getMax();
    }

    public function setMax(?int $max): void
    {
        $this->max = null !== $max && 0 > $max ? 0 : $max;
    }
}