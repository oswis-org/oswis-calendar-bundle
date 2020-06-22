<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegRange;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActivatedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_range")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantRange implements BasicInterface
{
    use BasicTrait;
    use ActivatedTrait;
    use DeletedTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="RegistrationRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegRange $range = null;

    public function __construct(?RegRange $range = null)
    {
        try {
            $this->setRange($range);
        } catch (NotImplementedException $e) {
        }
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->isActivated($referenceDateTime) && !$this->isDeleted($referenceDateTime);
    }

    public function getEventName(): ?string
    {
        return $this->getEvent() ? $this->getEvent()->getName() : null;
    }

    public function getEvent(): ?Event
    {
        return $this->getRange() ? $this->getRange()->getEvent() : null;
    }

    public function getRange(): ?RegRange
    {
        return $this->range;
    }

    /**
     * @param RegRange|null $range
     *
     * @throws NotImplementedException
     */
    public function setRange(?RegRange $range): void
    {
        if ($this->range === $range) {
            return;
        }
        if (null === $this->range) {
            $this->range = $range;
        }
        throw new NotImplementedException('změna rozsahu', 'v přiřazení rozsahu k účastníkovi');
    }
}
