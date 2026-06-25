<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventStaffAssignmentRepository;
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
#[Entity(repositoryClass: EventStaffAssignmentRepository::class)]
#[Table(name: 'calendar_event_staff_assignment')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class EventStaffAssignment implements BasicInterface
{
    use BasicTrait;
    use NoteTrait;

    /** Aktivita (Event), ke které je přiřazení vázáno. */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    protected Event $event;

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
        Event $event,
        ?Participant $participant = null,
        ?string $externalName = null,
        ?string $roleLabel = null,
    ) {
        $this->event = $event;
        $this->participant = $participant;
        $this->externalName = $externalName;
        $this->roleLabel = $roleLabel;
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addConstraint(new Assert\Callback('validateHasAssignee'));
    }

    public function validateHasAssignee(ExecutionContextInterface $context): void
    {
        if (null === $this->participant && (null === $this->externalName || '' === trim($this->externalName))) {
            $context->buildViolation('EventStaffAssignment musí mít buď účastníka, nebo externí jméno.')
                ->atPath('externalName')
                ->addViolation();
        }
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
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
