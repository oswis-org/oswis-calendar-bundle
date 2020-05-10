<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\AbstractClass;

use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ValueTrait;

abstract class AbstractEventFlagType implements NameableInterface
{
    use NameableTrait;
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
