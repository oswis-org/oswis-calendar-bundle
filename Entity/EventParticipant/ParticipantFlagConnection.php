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
use OswisOrg\OswisCoreBundle\Traits\Common\DateRangeTrait;
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
    use DateRangeTrait;

    /**
     * Event contact flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlag", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantFlag $participantFlag = null;

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
     * @param ParticipantFlag|null $participantFlag
     * @param Participant|null     $participant
     * @param string|null          $textValue
     * @param DateTime|null        $startDateTime
     * @param DateTime|null        $endDateTime
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?ParticipantFlag $participantFlag = null,
        ?Participant $participant = null,
        ?string $textValue = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null
    ) {
        $this->setParticipantFlag($participantFlag);
        $this->setParticipant($participant);
        $this->setTextValue($textValue);
        $this->setStartDateTime($startDateTime);
        $this->setEndDate($endDateTime);
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

    public function getParticipantFlag(): ?ParticipantFlag
    {
        return $this->participantFlag;
    }

    public function setParticipantFlag(?ParticipantFlag $participantFlag): void
    {
        $this->participantFlag = $participantFlag;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->containsDateTimeInRange($referenceDateTime);
    }
}
