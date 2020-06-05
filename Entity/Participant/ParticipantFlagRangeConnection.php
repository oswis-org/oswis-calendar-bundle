<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

/**
 * Flag assigned to event participant (ie. special food requirement...) through some "flag range".
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRangeConnectionRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_flag_range_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantFlagRangeConnection implements BasicInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DeletedMailConfirmationTrait;

    /**
     * Event contact flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantFlagRange $flagRange = null;

    public function __construct(?ParticipantFlagRange $flagRange = null, ?string $textValue = null)
    {
        try {
            $this->setFlagRange($flagRange);
            $this->setTextValue($textValue);
        } catch (OswisNotImplementedException $e) {
        }
    }

    public function getFlagTypeString(): ?string
    {
        return $this->getFlagType() ? $this->getFlagType()->getType() : null;
    }

    public function getFlagType(): ?ParticipantFlagType
    {
        return $this->getFlag() ? $this->getFlag()->getFlagType() : null;
    }

    public function getFlag(): ?ParticipantFlag
    {
        return $this->getFlagRange() ? $this->getFlagRange()->getFlag() : null;
    }

    public function getFlagRange(): ?ParticipantFlagRange
    {
        return $this->flagRange;
    }

    /**
     * @param ParticipantFlagRange|null $flagRange
     *
     * @throws OswisNotImplementedException
     */
    public function setFlagRange(?ParticipantFlagRange $flagRange): void
    {
        if ($this->flagRange === $flagRange) {
            return;
        }
        if (null === $this->flagRange) {
            $this->flagRange = $flagRange;
        }
        throw new OswisNotImplementedException('změna příznaku', 'v přiřazení příznaku');
    }

    public function getPrice(): int
    {
        return $this->isActive() && $this->getFlagRange() ? $this->getFlagRange()->getPrice() : 0;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return !$this->isDeleted($referenceDateTime);
    }

    public function getDepositValue(): int
    {
        return $this->isActive() && $this->getFlagRange() ? $this->getFlagRange()->getDepositValue() : 0;
    }
}
