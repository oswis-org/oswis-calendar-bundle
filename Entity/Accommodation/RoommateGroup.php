<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Accommodation;

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
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\RoommateGroupRepository;
use OswisOrg\OswisCalendarBundle\State\AccommodationApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Serializer\Attribute\MaxDepth;

/**
 * Skupina spolubydlících (per turnus) — „4 holky do jedné chatky". Constraint pro AccommodationService:
 * všichni členové do JEDNÉ jednotky. Vzniká z {@see RoommatePreference} (tým je resolvuje) nebo ručně.
 *
 * `event`/`members` IRI resolvuje AccommodationApiProcessor. Serializace: serialization/Accommodation/RoommateGroup.yaml.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_roommate_groups_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_roommate_group_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_roommate_groups_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: AccommodationApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_roommate_group_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_roommate_group_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: AccommodationApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['event.id'])]
#[Entity(repositoryClass: RoommateGroupRepository::class)]
#[Table(name: 'calendar_roommate_group')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class RoommateGroup implements NameableInterface, DeletedInterface
{
    use NameableTrait;
    use DeletedTrait;

    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    #[MaxDepth(1)]
    protected ?Event $event = null;

    /** @var Collection<int, Participant> */
    #[ManyToMany(targetEntity: Participant::class)]
    #[JoinTable(name: 'calendar_roommate_group_member')]
    #[MaxDepth(1)]
    protected Collection $members;

    public function __construct(?Nameable $nameable = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->members = new ArrayCollection();
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

    public function addMember(Participant $participant): void
    {
        if (!$this->members->contains($participant)) {
            $this->members->add($participant);
        }
    }

    public function removeMember(Participant $participant): void
    {
        $this->members->removeElement($participant);
    }

    public function getMemberCount(): int
    {
        return $this->members->count();
    }
}
