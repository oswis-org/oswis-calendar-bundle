<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
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
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Podtým v rámci turnusu (např. kuchyně, technika, program). Sdružuje vedoucí/účastníky
 * pro provozní rozdělení rolí.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_staff_teams_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_staff_team_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_staff_teams_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_staff_team_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_staff_team_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['event.id'])]
#[Entity(repositoryClass: StaffTeamRepository::class)]
#[Table(name: 'calendar_staff_team')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class StaffTeam implements NameableInterface
{
    use NameableTrait;

    /**
     * Turnus (Event), do kterého podtým patří. Nastavuje se setterem (NE konstruktorem) —
     * API Platform resolvuje IRI relace jen přes setter, ne přes constructor argument.
     */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Event $event = null;

    /** @var Collection<int, Participant> Členové podtýmu. */
    #[ManyToMany(targetEntity: Participant::class)]
    #[JoinTable(name: 'calendar_staff_team_member')]
    #[JoinColumn(name: 'staff_team_id', referencedColumnName: 'id')]
    #[InverseJoinColumn(name: 'participant_id', referencedColumnName: 'id')]
    protected Collection $members;

    public function __construct(?Nameable $nameable = null)
    {
        $this->members = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
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
