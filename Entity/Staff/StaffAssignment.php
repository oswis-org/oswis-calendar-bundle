<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Staff;

use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use DateTimeInterface;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use OswisOrg\OswisCalendarBundle\Repository\Staff\StaffAssignmentRepository;
use OswisOrg\OswisCalendarBundle\Service\Program\StaffNameFormatter;
use OswisOrg\OswisCalendarBundle\State\StaffAssignmentProcessor;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NoteTrait;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Mapping\ClassMetadata;

/**
 * Závazek člena týmu = *osoba/tým je zavázán k FUNKCI na nějaký ČAS, případně kvůli konkrétní AKTIVITĚ*.
 *
 * SJEDNOCUJE denní služby (řízení/jídelna) i per-aktivitní role (vede/technika/svolávání) i chystání —
 * je to genuinně jeden koncept (ne god-entita, viz [[reference_event_is_only_happenings]]). Vyvinuto
 * z dřívějšího `EventStaffAssignment` (které mělo jen `event`+`roleLabel`, žádný vlastní čas).
 * Návrh: docs/superpowers/specs/2026-07-19-staffing-model-design.md.
 *
 * Roviny obsazování zůstávají oddělené: členství v týmu = {@see Participant}+kategorie; podtýmy =
 * {@see StaffTeam}; TENTO závazek; přístupové role (#211) = jiná věc (nemíchat).
 */
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_MANAGER')", normalizationContext: ['groups' => ['entities_get', 'calendar_staff_assignments_get'], 'enable_max_depth' => true]),
        new Get(security: "is_granted('ROLE_MANAGER')", normalizationContext: ['groups' => ['entity_get', 'calendar_staff_assignment_get'], 'enable_max_depth' => true]),
        new Post(security: "is_granted('ROLE_MANAGER')", denormalizationContext: ['groups' => ['calendar_staff_assignments_post'], 'enable_max_depth' => true], processor: StaffAssignmentProcessor::class),
        new Put(security: "is_granted('ROLE_MANAGER')", normalizationContext: ['groups' => ['entity_get', 'calendar_staff_assignment_get'], 'enable_max_depth' => true], denormalizationContext: ['groups' => ['calendar_staff_assignment_put'], 'enable_max_depth' => true], processor: StaffAssignmentProcessor::class),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['turnus.id', 'activity.id', 'participant.id'])]
// Rozpis SLUŽEB = ?exists[activity]=false (bez konkrétní aktivity); role u aktivit = ?exists[activity]=true.
#[ApiFilter(ExistsFilter::class, properties: ['activity'])]
#[Entity(repositoryClass: StaffAssignmentRepository::class)]
#[Table(name: 'calendar_staff_assignment')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class StaffAssignment implements BasicInterface
{
    use BasicTrait;
    use NoteTrait;
    use DeletedTrait;

    /** Scope — turnus (vždy). Kvůli levnému filtrování „závazky turnusu X". */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'turnus_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Event $turnus = null;

    /** Konkrétní aktivita, ke které se závazek váže. NULL = celodenní služba (nevázaná na aktivitu). */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'activity_id', nullable: true)]
    protected ?Event $activity = null;

    /** Funkce/role ze sdíleného číselníku (řízení, technika, vede…). */
    #[ManyToOne(targetEntity: StaffRole::class)]
    #[JoinColumn(name: 'staff_role_id', nullable: true)]
    protected ?StaffRole $role = null;

    /** Interní účastník (člen týmu). */
    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'participant_id', nullable: true)]
    protected ?Participant $participant = null;

    /** Externí jméno (host bez registrace). */
    #[Column(type: 'string', nullable: true)]
    protected ?string $externalName = null;

    /** Celý podtým místo jednotlivce. */
    #[ManyToOne(targetEntity: StaffTeam::class)]
    #[JoinColumn(name: 'staff_team_id', nullable: true)]
    protected ?StaffTeam $team = null;

    /** Podtým „bez Franty" — explicitně vyřazen z týmového přiřazení. */
    #[Column(type: 'boolean', options: ['default' => false])]
    protected bool $excluded = false;

    // ── Čas: dva režimy (volba = „posune se to, když se aktivita hne?") ──
    // Absolutní má přednost; když je prázdný a je vyplněná `activity`, počítá se relativně z jejího času.

    /** ABSOLUTNÍ začátek (služby na turnusu; volně navázaná práce – ranní chystání). Fixní. */
    #[Column(type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $startDateTime = null;

    /** ABSOLUTNÍ konec. */
    #[Column(type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $endDateTime = null;

    /** RELATIVNÍ: kolik minut PŘED začátkem aktivity (svolávání/technika). Posune se s aktivitou. */
    #[Column(type: 'integer', nullable: true)]
    protected ?int $leadMinutes = null;

    /** RELATIVNÍ: je závazek i BĚHEM aktivity (technika ano; svolávání ne)? */
    #[Column(type: 'boolean', options: ['default' => true])]
    protected bool $coversDuring = true;

    /** RELATIVNÍ: kolik minut PO konci aktivity (technika/úklid). */
    #[Column(type: 'integer', nullable: true)]
    protected ?int $trailMinutes = null;

    public function __construct(?string $externalName = null)
    {
        $this->externalName = $externalName;
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addConstraint(new Assert\Callback('validateHasAssignee'));
    }

    public function validateHasAssignee(ExecutionContextInterface $context): void
    {
        $hasExternal = null !== $this->externalName && '' !== trim($this->externalName);
        if (null === $this->participant && !$hasExternal && null === $this->team) {
            $context->buildViolation('Závazek musí mít účastníka, tým, nebo externí jméno.')
                ->atPath('externalName')->addViolation();
        }
    }

    /**
     * Efektivní časový úsek závazku. Absolutní má přednost; jinak relativně z času aktivity
     * (lead/during/trail — viz design doc §4): before-only, během, after-only i napříč.
     *
     * @return array{0: ?DateTimeInterface, 1: ?DateTimeInterface} [start, end]
     */
    public function getEffectiveSpan(): array
    {
        if (null !== $this->startDateTime || null !== $this->endDateTime) {
            return [$this->startDateTime, $this->endDateTime];
        }
        $aStart = $this->activity?->getStartDateTimeRecursive();
        $aEnd = $this->activity?->getEndDateTimeRecursive();
        if (null === $aStart) {
            return [null, null];
        }
        $lead = max(0, $this->leadMinutes ?? 0);
        $trail = max(0, $this->trailMinutes ?? 0);
        if ($this->coversDuring) {
            return [$this->shift($aStart, -$lead), $this->shift($aEnd ?? $aStart, $trail)];
        }
        if ($lead > 0) {                       // jen před (svolávání)
            return [$this->shift($aStart, -$lead), $aStart];
        }
        if ($trail > 0 && null !== $aEnd) {    // jen po (úklid)
            return [$aEnd, $this->shift($aEnd, $trail)];
        }

        return [$aStart, $aEnd];               // degenerace → jako během
    }

    private function shift(DateTimeInterface $base, int $minutes): DateTimeInterface
    {
        $copy = \DateTime::createFromInterface($base);
        $copy->modify(sprintf('%+d minutes', $minutes));

        return $copy;
    }

    public function getEffectiveStart(): ?DateTimeInterface
    {
        return $this->getEffectiveSpan()[0];
    }

    public function getEffectiveEnd(): ?DateTimeInterface
    {
        return $this->getEffectiveSpan()[1];
    }

    /** Přezdývkový tvar jména pro rozpis (viz {@see StaffNameFormatter}); interní má přednost. */
    public function getStaffName(): ?string
    {
        if ($this->participant instanceof Participant) {
            $name = StaffNameFormatter::format($this->participant->getContactForRead());
            if ('' !== $name) {
                return $name;
            }
        }
        $external = trim((string) $this->externalName);

        return '' !== $external ? $external : null;
    }

    public function getTurnus(): ?Event
    {
        return $this->turnus;
    }

    public function setTurnus(?Event $turnus): void
    {
        $this->turnus = $turnus;
    }

    public function getActivity(): ?Event
    {
        return $this->activity;
    }

    public function setActivity(?Event $activity): void
    {
        $this->activity = $activity;
    }

    public function getRole(): ?StaffRole
    {
        return $this->role;
    }

    public function setRole(?StaffRole $role): void
    {
        $this->role = $role;
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

    public function getStartDateTime(): ?DateTimeInterface
    {
        return $this->startDateTime;
    }

    public function setStartDateTime(?DateTimeInterface $startDateTime): void
    {
        $this->startDateTime = $startDateTime;
    }

    public function getEndDateTime(): ?DateTimeInterface
    {
        return $this->endDateTime;
    }

    public function setEndDateTime(?DateTimeInterface $endDateTime): void
    {
        $this->endDateTime = $endDateTime;
    }

    public function getLeadMinutes(): ?int
    {
        return $this->leadMinutes;
    }

    public function setLeadMinutes(?int $leadMinutes): void
    {
        $this->leadMinutes = $leadMinutes;
    }

    public function isCoversDuring(): bool
    {
        return $this->coversDuring;
    }

    public function setCoversDuring(bool $coversDuring): void
    {
        $this->coversDuring = $coversDuring;
    }

    public function getTrailMinutes(): ?int
    {
        return $this->trailMinutes;
    }

    public function setTrailMinutes(?int $trailMinutes): void
    {
        $this->trailMinutes = $trailMinutes;
    }
}
