<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Entity\AbstractClass;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TypeTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ValueTrait;

abstract class AbstractEventFlagType
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use TypeTrait;
    use ValueTrait;

    /**
     * Minimal amount of flags used in one EventParticipant.
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $minInEventParticipant = null;

    /**
     * Maximal amount of flags used in one EventParticipant.
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $maxInEventParticipant = null;

    abstract public static function getAllowedTypesDefault(): array;

    abstract public static function getAllowedTypesCustom(): array;

    public function isInRangeInEventParticipant(int $amount): bool
    {
        $min = $this->getMinInEventParticipant();
        $max = $this->getMaxInEventParticipant();
        if (null !== $min && $amount < $min) {
            return false;
        }
        if (null !== $max && $amount > $max) {
            return false;
        }

        return true;
    }

    public function getMinInEventParticipant(): ?int
    {
        return $this->minInEventParticipant;
    }

    public function setMinInEventParticipant(?int $minInEventParticipant): void
    {
        $this->minInEventParticipant = $minInEventParticipant;
    }

    public function getMaxInEventParticipant(): ?int
    {
        return $this->maxInEventParticipant;
    }

    public function setMaxInEventParticipant(?int $maxInEventParticipant): void
    {
        $this->maxInEventParticipant = $maxInEventParticipant;
    }
}
