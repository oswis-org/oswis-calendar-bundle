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
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use DateTime;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\ReservationRepository;
use OswisOrg\OswisCalendarBundle\Service\Accommodation\AccommodationService;
use OswisOrg\OswisCalendarBundle\Service\Accommodation\AccommodationWarning;
use OswisOrg\OswisCalendarBundle\State\ReservationAssignProcessor;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rezervace / přiřazení ubytování — UNIVERZÁLNÍ (event-bound Seznamovák assign + growth do celoročního
 * Karlov booking). `occupant` = zatím `participant` (occupant-ready: pro Karlov se doplní AppUser occupant).
 * `status` lifecycle pokrývá i booking (inquiry→…→checked_out). `checkedInAt` = ubytovací stanice check-inu.
 * `bed` = konkrétní lůžko (per-bed); `fromDate`/`toDate` = partial-stay (různé pobyty v 1 jednotce = OK).
 *
 * ZÁPIS přes {@see \OswisOrg\OswisCalendarBundle\Service\Accommodation\AccommodationService} (kapacita +
 * constrainty) → API zatím READ-only. Serializace: serialization/Accommodation/Reservation.yaml.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_reservations_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_reservation_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        // Přiřazení ubytování z check-in stolu. Zápis jde přes AccommodationService (kapacita +
        // constrainty) — API ho NEobchází, jen ho zpřístupňuje. Bez téhle operace neměl stůl jak
        // pokoj přiřadit a zůstával u volného textu.
        new Post(
            normalizationContext: ['groups' => ['entity_get', 'calendar_reservation_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['calendar_reservation_post']],
            security: "is_granted('ROLE_MANAGER')",
            processor: ReservationAssignProcessor::class,
        ),
    ],
    security: "is_granted('ROLE_MEMBER')",
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['participant.id', 'unit.id', 'unit.facility.id', 'participant.event.id', 'status'])]
#[Entity(repositoryClass: ReservationRepository::class)]
#[Table(name: 'calendar_accommodation_reservation')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class Reservation implements BasicInterface
{
    use BasicTrait;

    public const string STATUS_INQUIRY = 'inquiry';
    public const string STATUS_TENTATIVE = 'tentative';
    public const string STATUS_CONFIRMED = 'confirmed';
    public const string STATUS_CHECKED_IN = 'checked_in';
    public const string STATUS_CHECKED_OUT = 'checked_out';
    public const string STATUS_NO_SHOW = 'no_show';
    public const string STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const array ALLOWED_STATUSES = [
        self::STATUS_INQUIRY,
        self::STATUS_TENTATIVE,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
        self::STATUS_CHECKED_OUT,
        self::STATUS_NO_SHOW,
        self::STATUS_CANCELLED,
    ];

    /** Occupant — v1 účastník; occupant-ready (Karlov year-round doplní AppUser occupanta). */
    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'participant_id', nullable: true)]
    #[MaxDepth(1)]
    protected ?Participant $participant = null;

    #[ManyToOne(targetEntity: AccommodationUnit::class)]
    #[JoinColumn(name: 'unit_id', nullable: false)]
    #[Assert\NotNull]
    #[MaxDepth(1)]
    protected ?AccommodationUnit $unit = null;

    /** Konkrétní lůžko (per-bed granularita) — null = jen na úrovni jednotky. */
    #[ManyToOne(targetEntity: Bed::class)]
    #[JoinColumn(name: 'bed_id', nullable: true, onDelete: 'SET NULL')]
    #[MaxDepth(1)]
    protected ?Bed $bed = null;

    #[Column(type: 'string', length: 16)]
    #[Assert\Choice(choices: self::ALLOWED_STATUSES)]
    protected string $status = self::STATUS_CONFIRMED;

    /**
     * MĚKKÁ varování z {@see AccommodationService} (kapacita, nedostupná jednotka, nesoulad s typem
     * ubytování…). NEUKLÁDAJÍ SE — jdou jen do odpovědi na přiřazení, aby je obsluha u stolu viděla.
     * NEblokují: výjimky jsou u příjezdu běžné (ZTP, páry, kamarádi) a user chce „upozorňovat,
     * netvrdě zakazovat".
     *
     * @var list<array{code: string, message: string}>
     */
    private array $warnings = [];

    /** Partial-stay: od kdy (null = od začátku akce). */
    #[Column(type: 'date', nullable: true)]
    protected ?DateTime $fromDate = null;

    /** Partial-stay: do kdy (null = do konce akce). */
    #[Column(type: 'date', nullable: true)]
    protected ?DateTime $toDate = null;

    /** Ubytovací stanice check-inu (výdej klíče) — null = přiřazen, ale ještě nenastěhován. */
    #[Column(type: 'datetime', nullable: true)]
    protected ?DateTime $checkedInAt = null;

    /** Záloha (jen pro celoroční booking — Seznamovák nepoužije). */
    #[Column(type: 'integer', nullable: true)]
    protected ?int $depositAmount = null;

    #[Column(type: 'datetime', nullable: true)]
    protected ?DateTime $depositPaidAt = null;

    public function __construct(?DateTime $fromDate = null, ?DateTime $toDate = null, string $status = self::STATUS_CONFIRMED)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->setStatus($status);
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        $this->participant = $participant;
    }

    public function getUnit(): ?AccommodationUnit
    {
        return $this->unit;
    }

    public function setUnit(?AccommodationUnit $unit): void
    {
        $this->unit = $unit;
    }

    public function getBed(): ?Bed
    {
        return $this->bed;
    }

    public function setBed(?Bed $bed): void
    {
        $this->bed = $bed;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException("Neplatný stav rezervace: '$status'.");
        }
        $this->status = $status;
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param list<AccommodationWarning> $warnings
     */
    public function setWarnings(array $warnings): void
    {
        $this->warnings = array_map(
            static fn (AccommodationWarning $w): array => ['code' => $w->code, 'message' => $w->message],
            $warnings,
        );
    }

    public function getFromDate(): ?DateTime
    {
        return $this->fromDate;
    }

    public function setFromDate(?DateTime $fromDate): void
    {
        $this->fromDate = $fromDate;
    }

    public function getToDate(): ?DateTime
    {
        return $this->toDate;
    }

    public function setToDate(?DateTime $toDate): void
    {
        $this->toDate = $toDate;
    }

    public function getCheckedInAt(): ?DateTime
    {
        return $this->checkedInAt;
    }

    public function setCheckedInAt(?DateTime $checkedInAt): void
    {
        $this->checkedInAt = $checkedInAt;
        if (null !== $checkedInAt && self::STATUS_CONFIRMED === $this->status) {
            $this->status = self::STATUS_CHECKED_IN;
        }
    }

    public function getDepositAmount(): ?int
    {
        return $this->depositAmount;
    }

    public function setDepositAmount(?int $depositAmount): void
    {
        $this->depositAmount = $depositAmount;
    }

    public function getDepositPaidAt(): ?DateTime
    {
        return $this->depositPaidAt;
    }

    public function setDepositPaidAt(?DateTime $depositPaidAt): void
    {
        $this->depositPaidAt = $depositPaidAt;
    }
}
