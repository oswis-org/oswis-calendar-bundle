<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagRange;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

/**
 * Flag assigned to event participant (ie. special food requirement...) through some "flag range".
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRangeConnectionRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_category_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantFlag implements BasicInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DeletedMailConfirmationTrait;

    /**
     * Event contact flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationFlagRange $flagRange = null;

    /**
     * Parent event (if this is not top level event).
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagCategory", inversedBy="subEvents", fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantFlagCategory $participantFlagCategory = null;

    public function __construct(?RegistrationFlagRange $flagRange = null, ?string $textValue = null)
    {
        $this->setFlagRange($flagRange);
        $this->setTextValue($textValue);
    }

    public function getFlagType(): ?string
    {
        return $this->getFlagRange() ? $this->getFlagRange()->getType() : null;
    }

    public function getFlagCategory(): ?RegistrationFlagCategory
    {
        return $this->getFlagRange() ? $this->getFlagRange()->getCategory() : null;
    }

    public function getFlag(): ?RegistrationFlag
    {
        return $this->getFlagRange() ? $this->getFlagRange()->getFlag() : null;
    }

    public function getFlagRange(): ?RegistrationFlagRange
    {
        return $this->flagRange;
    }

    public function setFlagRange(?RegistrationFlagRange $flagRange): void
    {
        if ($this->flagRange === $flagRange) {
            return;
        }
        if (null === $this->flagRange) {
            $this->flagRange = $flagRange;
        }
    }

    public function getPrice(): int
    {
        return $this->isActive() && $this->getFlagRange() ? $this->getFlagRange()->getPrice() : 0;
    }

    public function getDepositValue(): int
    {
        return $this->isActive() && $this->getFlagRange() ? $this->getFlagRange()->getDepositValue() : 0;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return !$this->isDeleted($referenceDateTime);
    }

    public function getParticipantFlagCategory(): ?ParticipantFlagCategory
    {
        return $this->participantFlagCategory;
    }

    /**
     * @param ParticipantFlagCategory|null $participantFlagCategory
     *
     * @throws InvalidTypeException
     */
    public function setParticipantFlagCategory(?ParticipantFlagCategory $participantFlagCategory): void
    {
        if ($this->participantFlagCategory && $participantFlagCategory !== $this->participantFlagCategory) {
            $this->participantFlagCategory->removeParticipantFlag($this);
        }
        $this->participantFlagCategory = $participantFlagCategory;
        if ($this->participantFlagCategory) {
            $this->participantFlagCategory->addParticipantFlag($this);
        }
    }
}
