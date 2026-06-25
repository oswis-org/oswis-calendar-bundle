<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Participant\StaffTeamRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * Podtým v rámci turnusu (např. kuchyně, technika, program). Sdružuje vedoucí/účastníky
 * pro provozní rozdělení rolí.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[Entity(repositoryClass: StaffTeamRepository::class)]
#[Table(name: 'calendar_staff_team')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class StaffTeam implements NameableInterface
{
    use NameableTrait;

    /** Turnus (Event), do kterého podtým patří. */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    protected Event $event;

    /** @var Collection<int, Participant> Členové podtýmu. */
    #[ManyToMany(targetEntity: Participant::class)]
    #[JoinTable(name: 'calendar_staff_team_member')]
    #[JoinColumn(name: 'staff_team_id', referencedColumnName: 'id')]
    #[InverseJoinColumn(name: 'participant_id', referencedColumnName: 'id')]
    protected Collection $members;

    public function __construct(Event $event, ?Nameable $nameable = null)
    {
        $this->event = $event;
        $this->members = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    /** @return Collection<int, Participant> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(?Participant $participant): void
    {
        if ($participant && !$this->members->contains($participant)) {
            $this->members->add($participant);
        }
    }

    public function removeMember(?Participant $participant): void
    {
        if ($participant) {
            $this->members->removeElement($participant);
        }
    }
}
