<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventStaffAssignmentRepository;
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NoteTrait;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Mapping\ClassMetadata;

/**
 * Přiřazení vedoucího/služby k aktivitě (Event). Buď interní účastník, nebo externí jméno
 * (např. host bez registrace). `excluded` = explicitně vyřazen (nemá službu na této aktivitě).
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_event_staff_assignments_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_event_staff_assignment_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_event_staff_assignments_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_event_staff_assignment_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_event_staff_assignment_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['event.id', 'participant.id'])]
#[Entity(repositoryClass: EventStaffAssignmentRepository::class)]
#[Table(name: 'calendar_event_staff_assignment')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class EventStaffAssignment implements BasicInterface
{
    use BasicTrait;
    use NoteTrait;

    /**
     * Aktivita (Event), ke které je přiřazení vázáno. Nastavuje se setterem (NE konstruktorem) —
     * API Platform resolvuje IRI relace jen přes setter, ne přes constructor argument.
     */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Event $event = null;

    /** Interní účastník (pokud přiřazení odkazuje na registrovaného). */
    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'participant_id', nullable: true)]
    protected ?Participant $participant = null;

    /** Externí jméno (pokud není interní účastník). */
    #[Column(type: 'string', nullable: true)]
    protected ?string $externalName = null;

    /** Popis role (např. „hlavní vedoucí", „dozor"). */
    #[Column(type: 'string', nullable: true)]
    protected ?string $roleLabel = null;

    /** Podtým, v jehož rámci je přiřazení (nepovinné). */
    #[ManyToOne(targetEntity: StaffTeam::class)]
    #[JoinColumn(name: 'staff_team_id', nullable: true)]
    protected ?StaffTeam $team = null;

    /** Explicitně vyřazen z této aktivity (nemá službu). */
    #[Column(type: 'boolean', options: ['default' => false])]
    protected bool $excluded = false;

    public function __construct(
        ?string $externalName = null,
        ?string $roleLabel = null,
    ) {
        $this->externalName = $externalName;
        $this->roleLabel = $roleLabel;
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addConstraint(new Assert\Callback('validateHasAssignee'));
    }

    public function validateHasAssignee(ExecutionContextInterface $context): void
    {
        // Dle specu je přiřazení buď osoba (účastník / externí jméno) NEBO tým/podtým. Dřív validátor
        // tým nezohledňoval → přiřazení celého týmu by neprošlo.
        $hasExternal = null !== $this->externalName && '' !== trim($this->externalName);
        if (null === $this->participant && !$hasExternal && null === $this->team) {
            $context->buildViolation('EventStaffAssignment musí mít účastníka, tým, nebo externí jméno.')
                ->atPath('externalName')
                ->addViolation();
        }
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        $this->participant = $participant;
    }

    public function getExternalName(): ?string
    {
        return $this->externalName;
    }

    public function setExternalName(?string $externalName): void
    {
        $this->externalName = $externalName;
    }

    public function getRoleLabel(): ?string
    {
        return $this->roleLabel;
    }

    public function setRoleLabel(?string $roleLabel): void
    {
        $this->roleLabel = $roleLabel;
    }

    public function getTeam(): ?StaffTeam
    {
        return $this->team;
    }

    public function setTeam(?StaffTeam $team): void
    {
        $this->team = $team;
    }

    public function isExcluded(): bool
    {
        return $this->excluded;
    }

    public function setExcluded(bool $excluded): void
    {
        $this->excluded = $excluded;
    }

    /** Zobrazitelné jméno přiřazené osoby (interní účastník má přednost). */
    public function getDisplayName(): ?string
    {
        return $this->participant?->getName() ?? $this->externalName;
    }
}
