<?php
/**
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use DateTime;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActivatedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: [
                'groups' => ['entities_get', 'calendar_participant_contacts_get'],
                'enable_max_depth' => true,
            ],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Post(
            denormalizationContext: [
                'groups' => ['entities_post', 'calendar_participant_contacts_post'],
                'enable_max_depth' => true,
            ],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Get(
            normalizationContext: [
                'groups' => ['entity_get', 'calendar_participant_contact_get'],
                'enable_max_depth' => true,
            ],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Put(
            denormalizationContext: [
                'groups' => ['entity_put', 'calendar_participant_contact_put'],
                'enable_max_depth' => true,
            ],
            security: "is_granted('ROLE_MANAGER')"
        ),
    ],
    filters: ['search'],
    security: "is_granted('ROLE_MANAGER')"
)]
#[Entity]
#[Table(name: 'calendar_participant_contact')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class ParticipantContact implements BasicInterface
{
    use BasicTrait;
    use ActivatedTrait;
    use DeletedTrait;

    #[ManyToOne(targetEntity: Participant::class, fetch: 'EXTRA_LAZY', inversedBy: 'participantContacts')]
    #[JoinColumn(nullable: true)]
    protected ?Participant $participant = null;

    #[ManyToOne(targetEntity: AbstractContact::class, fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    protected ?AbstractContact $contact = null;

    public function __construct(?AbstractContact $contact = null)
    {
        try {
            $this->setContact($contact);
        } catch (NotImplementedException) {
        }
    }

    public function getContact(): ?AbstractContact
    {
        return $this->contact;
    }

    /**
     * @param AbstractContact|null $contact
     *
     * @throws NotImplementedException
     */
    public function setContact(?AbstractContact $contact): void
    {
        if ($this->contact === $contact) {
            return;
        }
        if (null !== $this->contact) {
            throw new NotImplementedException('změna kontaktu', 'v přiřazení kontaktu k události');
        }
        $this->contact = $contact;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->isActivated($referenceDateTime) && !$this->isDeleted($referenceDateTime);
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        $this->participant = $participant;
    }
}
