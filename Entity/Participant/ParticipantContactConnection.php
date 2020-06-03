<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActiveTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_contact_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantContactConnection implements BasicInterface
{
    use BasicTrait;
    use ActiveTrait;
    use DeletedTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?AbstractContact $contact = null;

    public function __construct(?AbstractContact $contact = null)
    {
        try {
            $this->setContact($contact);
        } catch (OswisNotImplementedException $e) {
        }
    }

    public function getContact(): ?AbstractContact
    {
        return $this->contact;
    }

    /**
     * @param AbstractContact|null $contact
     *
     * @throws OswisNotImplementedException
     */
    public function setContact(?AbstractContact $contact): void
    {
        if ($this->contact === $contact) {
            return;
        }
        if (null === $this->contact) {
            $this->contact = $contact;
        }
        throw new OswisNotImplementedException('změna kontaktu', 'v přiřazení kontaktu k události');
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return !$this->isDeleted($referenceDateTime);
    }
}
