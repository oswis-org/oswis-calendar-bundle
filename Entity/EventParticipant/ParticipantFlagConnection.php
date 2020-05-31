<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use DateTime;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

/**
 * Flag assigned to event participant (ie. special food requirement...).
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_flag_new_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantFlagConnection implements BasicInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DeletedTrait;

    /**
     * Event contact flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlagRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantFlagRange $participantFlagRange = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\Participant",
     *     inversedBy="participantFlagConnections"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Participant $participant = null;

    /**
     * FlagInEmployerInEvent constructor.
     *
     * @param ParticipantFlagRange|null $participantFlagRange
     * @param Participant|null          $participant
     * @param string|null               $textValue
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?ParticipantFlagRange $participantFlagRange = null,
        ?Participant $participant = null,
        ?string $textValue = null
    ) {
        $this->setParticipantFlagRange($participantFlagRange);
        $this->setParticipant($participant);
        $this->setTextValue($textValue);
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    /**
     * @param Participant|null $participant
     *
     * @throws EventCapacityExceededException
     */
    public function setParticipant(?Participant $participant): void
    {
        if ($this->participant && $participant !== $this->participant) {
            $this->participant->removeParticipantFlagConnection($this);
        }
        if ($participant && $this->participant !== $participant) {
            $this->participant = $participant;
            $participant->addParticipantFlagConnection($this);
        }
    }

    public function getParticipantFlagRange(): ?ParticipantFlagRange
    {
        return $this->participantFlagRange;
    }

    public function setParticipantFlagRange(?ParticipantFlagRange $participantFlagRange): void
    {
        $this->participantFlagRange = $participantFlagRange;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return !$this->isDeleted($referenceDateTime);
    }

    public function getPrice(): int
    {
        return $this->isActive() && $this->getParticipantFlagRange() ? $this->getParticipantFlagRange()->getPrice() : 0;
    }

    public function getDepositValue(): int
    {
        return $this->isActive() && $this->getParticipantFlagRange() ? $this->getParticipantFlagRange()->getDepositValue() : 0;
    }
}
