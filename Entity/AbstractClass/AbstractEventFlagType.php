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
     * Minimal amount of flags used in one Participant.
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $minInParticipant = null;

    /**
     * Maximal amount of flags used in one Participant.
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     */
    protected ?int $maxInParticipant = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?string $emptyPlaceholder = null;

    public function __construct(?int $minInParticipant = null, ?int $maxInParticipant = null, ?string $emptyPlaceholder = null)
    {
        $this->minInParticipant = $minInParticipant;
        $this->maxInParticipant = $maxInParticipant;
        $this->emptyPlaceholder = $emptyPlaceholder;
    }

    abstract public static function getAllowedTypesDefault(): array;

    abstract public static function getAllowedTypesCustom(): array;

    public function isInRangeInParticipant(int $amount): bool
    {
        $min = $this->getMinInParticipant();
        $max = $this->getMaxInParticipant();
        if (null !== $min && $amount < $min) {
            return false;
        }
        if (null !== $max && $amount > $max) {
            return false;
        }

        return true;
    }

    public function getMinInParticipant(): ?int
    {
        return $this->minInParticipant;
    }

    public function setMinInParticipant(?int $minInParticipant): void
    {
        $this->minInParticipant = $minInParticipant;
    }

    public function getMaxInParticipant(): ?int
    {
        return $this->maxInParticipant;
    }

    public function setMaxInParticipant(?int $maxInParticipant): void
    {
        $this->maxInParticipant = $maxInParticipant;
    }

    public function getEmptyPlaceholder(): ?string
    {
        return $this->emptyPlaceholder;
    }

    public function setEmptyPlaceholder(?string $emptyPlaceholder): void
    {
        $this->emptyPlaceholder = $emptyPlaceholder;
    }
}
