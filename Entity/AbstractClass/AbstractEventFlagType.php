<?php

namespace Zakjakub\OswisCalendarBundle\Entity\AbstractClass;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TypeTrait;

abstract class AbstractEventFlagType
{

    use BasicEntityTrait;
    use NameableBasicTrait;
    use TypeTrait;

    /**
     * Minimal amount of flags used.
     * @var int|null
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected $minFlagsAllowed;

    /**
     * Maximal amount of flags used.
     * @var int|null
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected $maxFlagsAllowed;

    abstract public static function getAllowedTypesDefault(): array;

    abstract public static function getAllowedTypesCustom(): array;

    final public function isAmountOfFlagsValid(int $amount): bool
    {
        return $amount >= $this->getMinFlagsAllowed() && $amount <= $this->getMaxFlagsAllowed();
    }

    /**
     * @return int|null
     */
    final public function getMinFlagsAllowed(): ?int
    {
        return $this->minFlagsAllowed;
    }

    /**
     * @param int|null $minFlagsAllowed
     */
    final public function setMinFlagsAllowed(?int $minFlagsAllowed): void
    {
        $this->minFlagsAllowed = $minFlagsAllowed;
    }

    /**
     * @return int|null
     */
    final public function getMaxFlagsAllowed(): ?int
    {
        return $this->maxFlagsAllowed;
    }

    /**
     * @param int|null $maxFlagsAllowed
     */
    final public function setMaxFlagsAllowed(?int $maxFlagsAllowed): void
    {
        $this->maxFlagsAllowed = $maxFlagsAllowed;
    }

}