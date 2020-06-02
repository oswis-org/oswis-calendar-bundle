<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_range_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantRangeConnection implements BasicInterface
{
    use BasicTrait;
    use DeletedTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationsRange $range = null;

    public function __construct(?RegistrationsRange $range = null)
    {
        try {
            $this->setRange($range);
        } catch (OswisNotImplementedException $e) {
        }
    }

    public function getRange(): ?RegistrationsRange
    {
        return $this->range;
    }

    /**
     * @param RegistrationsRange|null $range
     *
     * @throws OswisNotImplementedException
     */
    public function setRange(?RegistrationsRange $range): void
    {
        if ($this->range === $range) {
            return;
        }
        if (null === $this->range) {
            $this->range = $range;
        }
        throw new OswisNotImplementedException('změna rozsahu', 'v přiřazení rozsahu k účastníkovi');
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return !$this->isDeleted($referenceDateTime);
    }
}
