<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use OswisOrg\OswisCalendarBundle\Entity\Registration\Flag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagRange;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActivatedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

/**
 * Flag assigned to event participant (ie. special food requirement...) through some "flag range".
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_flag")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantFlag implements BasicInterface
{
    use BasicTrait;
    use TextValueTrait;
    use ActivatedTrait;
    use DeletedTrait;

    /**
     * Event contact flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\FlagRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?FlagRange $flagRange = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup", inversedBy="participantFlags", fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantFlagGroup $participantFlagGroup = null;

    public function __construct(?FlagRange $flagRange = null, ParticipantFlagGroup $participantFlagGroup = null, ?string $textValue = null)
    {
        try {
            $this->setParticipantFlagGroup($participantFlagGroup);
        } catch (NotImplementedException $e) {
        }
        $this->setFlagRange($flagRange);
        $this->setTextValue($textValue);
    }

    public function getFlagType(): ?string
    {
        return $this->getFlagRange() ? $this->getFlagRange()->getType() : null;
    }

    public function getFlagRange(): ?FlagRange
    {
        return $this->flagRange;
    }

    public function setFlagRange(?FlagRange $flagRange): void
    {
        if ($this->flagRange === $flagRange) {
            return;
        }
        if (null === $this->flagRange) {
            $this->flagRange = $flagRange;
        }
    }

    public function getFlagCategory(): ?FlagCategory
    {
        return $this->getFlagRange() ? $this->getFlagRange()->getCategory() : null;
    }

    public function getFlag(): ?Flag
    {
        return $this->getFlagRange() ? $this->getFlagRange()->getFlag() : null;
    }

    public function getPrice(): int
    {
        return $this->isActive() && $this->getFlagRange() ? $this->getFlagRange()->getVariableSymbol() : 0;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->isActivated() && !$this->isDeleted($referenceDateTime);
    }

    public function getDepositValue(): int
    {
        return $this->isActive() && $this->getFlagRange() ? $this->getFlagRange()->getDepositValue() : 0;
    }

    public function getParticipantFlagGroup(): ?ParticipantFlagGroup
    {
        return $this->participantFlagGroup;
    }

    /**
     * @param ParticipantFlagGroup|null $participantFlagGroup
     *
     * @throws NotImplementedException
     */
    public function setParticipantFlagGroup(?ParticipantFlagGroup $participantFlagGroup): void
    {
        if ($this->participantFlagGroup && $participantFlagGroup !== $this->participantFlagGroup) {
            throw new NotImplementedException('změna skupiny', 'u použití příznaku');
        }
        $this->participantFlagGroup = $participantFlagGroup;
    }

    public function getName(): ?string
    {
        return $this->getFlagRange() ? $this->getFlagRange()->getName() : null;
    }
}
