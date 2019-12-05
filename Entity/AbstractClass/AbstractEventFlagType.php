<?php /** @noinspection PhpUnused */

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
    protected ?int $minInEventParticipant = null;

    /**
     * Maximal amount of flags used.
     * @var int|null
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $maxInEventParticipant = null;

    abstract public static function getAllowedTypesDefault(): array;

    abstract public static function getAllowedTypesCustom(): array;

    final public function isInRangeInEventParticipant(int $amount): bool
    {
        return $amount >= $this->getMinInEventParticipant() && $amount <= $this->getMaxInEventParticipant();
    }

    /**
     * @return int|null
     */
    final public function getMinInEventParticipant(): ?int
    {
        return $this->minInEventParticipant;
    }

    /**
     * @param int|null $minInEventParticipant
     */
    final public function setMinInEventParticipant(?int $minInEventParticipant): void
    {
        $this->minInEventParticipant = $minInEventParticipant;
    }

    /**
     * @return int|null
     */
    final public function getMaxInEventParticipant(): ?int
    {
        return $this->maxInEventParticipant;
    }

    /**
     * @param int|null $maxInEventParticipant
     */
    final public function setMaxInEventParticipant(?int $maxInEventParticipant): void
    {
        $this->maxInEventParticipant = $maxInEventParticipant;
    }
}
