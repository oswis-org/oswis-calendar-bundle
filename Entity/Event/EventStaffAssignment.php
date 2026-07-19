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
use OswisOrg\OswisCalendarBundle\Service\Program\StaffNameFormatter;
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCalendarBundle\State\RosterShiftAssignProcessor;
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
        // Rozpis služeb pro web/Ionic editor: TÁŽ entita, jen lean serializační grupa
        // (`calendar_service_roster`) = ploché řádky (kdo · den · čas · typ služby), ze kterých si
        // klient poskládá tabulku „den × typ služby". Scopnutí na service-eventy daného turnusu
        // řeší ServiceRosterScopeExtension přes `?turnus=<slug>` (mimo ni vrací prázdno — bezpečné).
        new GetCollection(
            uriTemplate: '/program_service_roster',
            normalizationContext: ['groups' => ['calendar_service_roster'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        // Zápis směny: command payload (turnus/serviceType/date/start/end + kdo) → RosterShiftAssignProcessor
        // najde-nebo-založí service-Event a přiřadí. Denorm grupa je PRÁZDNÁ + allow_extra_attributes:
        // command pole nejsou vlastnosti entity, procesor je čte z raw payloadu; denormalizované $data
        // se zahodí. Validace přeskočena nepoužitou grupou — jinak by callback validateHasAssignee spadl
        // na prázdném $data 422 dřív, než procesor sestaví reálné přiřazení (to validuje assignShift samo).
        new Post(
            uriTemplate: '/program_service_roster',
            normalizationContext: ['groups' => ['calendar_service_roster'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['calendar_service_roster_command'], 'allow_extra_attributes' => true],
            validationContext: ['groups' => ['calendar_service_roster_command']],
            security: "is_granted('ROLE_MANAGER')",
            processor: RosterShiftAssignProcessor::class,
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

    /** Zobrazitelné jméno přiřazené osoby (interní účastník má přednost). PLNÉ jméno. */
    public function getDisplayName(): ?string
    {
        return $this->participant?->getName() ?? $this->externalName;
    }

    /**
     * Zobrazované jméno pro ROZPIS SLUŽEB — přezdívkový tvar („GABČA", „KUBA V.") přes
     * {@see StaffNameFormatter}. Na rozdíl od {@see getDisplayName()}, které vrací PLNÉ jméno;
     * v rozpisu služeb se lidé vedou přezdívkami (viz dokument „SLUŽBY"). Interní účastník má
     * přednost, jinak externí jméno. Slouží leanové serializaci roštu (grupa `calendar_service_roster`).
     */
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

    // ── Leanové view-accessory pro rozpis služeb (serializační grupa `calendar_service_roster`) ──
    // Rozpis služeb = tabulka „den × typ služby × směna". Klient si ji z těchto PLOCHÝCH skalárů
    // poskládá sám (seskupí dle serviceType + shiftDate, seřadí dle shiftStartTime). Data jsou
    // odvozená ze service-Eventu daného přiřazení; držíme je plochá (ne vnořený Event objekt)
    // kvůli předvídatelné serializaci a snadnému pivotu na klientu — stejný přístup jako lean
    // check-in fronta. Čas směny = vlastní rozsah service-Eventu (rozhodnutí „časově, ne půldny").

    /** Typ služby (`EventCategory::SERVICE_*`) = sloupec tabulky služeb. */
    public function getServiceType(): ?string
    {
        return $this->event?->getCategory()?->getType();
    }

    /** Název typu služby (např. „Řízení") = popisek sloupce. */
    public function getServiceName(): ?string
    {
        return $this->event?->getCategory()?->getName();
    }

    /** Barva typu služby = barva sloupce/čipu. */
    public function getServiceColor(): ?string
    {
        return $this->event?->getCategory()?->getColor();
    }

    /** Datum směny (řádek tabulky), `Y-m-d`. */
    public function getShiftDate(): ?string
    {
        return $this->event?->getStartDateTimeRecursive()?->format('Y-m-d');
    }

    /** Začátek směny, `H:i`. */
    public function getShiftStartTime(): ?string
    {
        return $this->event?->getStartDateTimeRecursive()?->format('H:i');
    }

    /** Konec směny, `H:i`. */
    public function getShiftEndTime(): ?string
    {
        return $this->event?->getEndDateTimeRecursive()?->format('H:i');
    }

    /** Název podtýmu, když je přiřazen celý tým místo jednotlivce (jinak `null`). */
    public function getTeamName(): ?string
    {
        return $this->team?->getName();
    }
}
