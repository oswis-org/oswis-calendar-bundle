<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\CheckIn;

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
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\CheckIn\CheckInStationRepository;
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Stanice check-in pipeline — konfigurovatelné stanoviště příjezdového dne per turnus (Event).
 * Např. evidence, pásky, ubytování, strava, tričko, bezpečnost. Nastavitelné per nasazení/akci
 * (klonovatelné přes „Klonovat ročník"). Splnění stanice účastníkem = {@see ParticipantStationVisit}.
 *
 * Serializační grupy: Resources/config/serialization/CheckIn/CheckInStation.yaml (konvence = YAML, ne atributy).
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§4).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_check_in_stations_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_check_in_station_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_check_in_stations_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_check_in_station_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_check_in_station_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['event.id', 'stationKind'])]
#[Entity(repositoryClass: CheckInStationRepository::class)]
#[Table(name: 'calendar_check_in_station')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class CheckInStation implements NameableInterface, DeletedInterface
{
    use NameableTrait;
    use DeletedTrait;

    /** Evidence — jediná hledá v plném seznamu; zapisuje arrivedAt; platby; ad-hoc přidání. */
    public const string KIND_EVIDENCE = 'evidence';

    /** Pásky — vydání pásku skupiny (ParticipantGroup); barva se doplní on-site. */
    public const string KIND_WRISTBAND = 'wristband';

    /** Ubytování — assign-during-check-in proti Accommodation modelu; výdej klíče. requiresOnline. */
    public const string KIND_ACCOMMODATION = 'accommodation';

    /** Strava — read-view dietních flagů (kuchyň/medik). */
    public const string KIND_FOOD = 'food';

    /** Tričko — hodnota = vydaná velikost/varianta (default z objednané = flag TYPE_T_SHIRT_SIZE). */
    public const string KIND_TSHIRT = 'tshirt';

    /** Bezpečnost — předvyplněné PDF → podpis → membership opt-in. */
    public const string KIND_SAFETY = 'safety';

    /** Obecná — jen „hotovo" + volitelná hodnota/poznámka. */
    public const string KIND_GENERIC = 'generic';

    /** @var list<string> */
    public const array ALLOWED_KINDS = [
        self::KIND_EVIDENCE,
        self::KIND_WRISTBAND,
        self::KIND_ACCOMMODATION,
        self::KIND_FOOD,
        self::KIND_TSHIRT,
        self::KIND_SAFETY,
        self::KIND_GENERIC,
    ];

    /**
     * Turnus (Event), na kterém stanice platí. Setter, ne konstruktor — API Platform resolvuje
     * IRI relace jen přes setter ({@see reference_apiplatform_relation_iri_on_post}).
     */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Event $event = null;

    /** Typ stanice (řídí station-specific chování + role-view). Viz KIND_* konstanty. */
    #[Column(type: 'string', length: 32)]
    #[Assert\Choice(choices: self::ALLOWED_KINDS)]
    protected string $stationKind = self::KIND_GENERIC;

    /** Pořadí stanice v pipeline (nižší = dřív). */
    #[Column(type: 'integer', nullable: true)]
    protected ?int $orderNumber = null;

    /** Ikona (ion-icon název) pro UI stanice. */
    #[Column(type: 'string', length: 64, nullable: true)]
    protected ?string $icon = null;

    /** Zachytává stanice hodnotu (velikost trička / barva pásku / číslo pokoje)? */
    #[Column(type: 'boolean')]
    protected bool $capturesValue = false;

    /** Popisek zachytávané hodnoty (např. „Velikost trička"). */
    #[Column(type: 'string', length: 128, nullable: true)]
    protected ?string $valueLabel = null;

    /**
     * Číselník voleb hodnoty (S/M/L/XL pro tričko, barvy pásků…). Prázdné = volný text.
     *
     * @var list<string>|null
     */
    #[Column(type: 'json', nullable: true)]
    protected ?array $valueOptions = null;

    /** Volitelná vazba na funkční roli (#211) pro budoucí role-scoped view; v1 nevynucováno. */
    #[Column(type: 'string', length: 32, nullable: true)]
    protected ?string $functionalRole = null;

    /** Vyžaduje online spojení (sdílený zdroj — kapacita ubytování). Default u kind=accommodation. */
    #[Column(type: 'boolean')]
    protected bool $requiresOnline = false;

    public function __construct(
        ?Nameable $nameable = null,
        string $stationKind = self::KIND_GENERIC,
        ?int $orderNumber = null,
    ) {
        $this->setFieldsFromNameable($nameable);
        $this->setStationKind($stationKind);
        $this->orderNumber = $orderNumber;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    public function getStationKind(): string
    {
        return $this->stationKind;
    }

    public function setStationKind(string $stationKind): void
    {
        if (!in_array($stationKind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException("Neplatný typ stanice: '$stationKind'.");
        }
        $this->stationKind = $stationKind;
        if (self::KIND_ACCOMMODATION === $stationKind && !$this->requiresOnline) {
            $this->requiresOnline = true;
        }
    }

    public function getOrderNumber(): ?int
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(?int $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): void
    {
        $this->icon = $icon;
    }

    public function isCapturesValue(): bool
    {
        return $this->capturesValue;
    }

    public function setCapturesValue(bool $capturesValue): void
    {
        $this->capturesValue = $capturesValue;
    }

    public function getValueLabel(): ?string
    {
        return $this->valueLabel;
    }

    public function setValueLabel(?string $valueLabel): void
    {
        $this->valueLabel = $valueLabel;
    }

    /** @return list<string>|null */
    public function getValueOptions(): ?array
    {
        return $this->valueOptions;
    }

    /** @param list<string>|null $valueOptions */
    public function setValueOptions(?array $valueOptions): void
    {
        $this->valueOptions = $valueOptions;
    }

    public function getFunctionalRole(): ?string
    {
        return $this->functionalRole;
    }

    public function setFunctionalRole(?string $functionalRole): void
    {
        $this->functionalRole = $functionalRole;
    }

    public function isRequiresOnline(): bool
    {
        return $this->requiresOnline;
    }

    public function setRequiresOnline(bool $requiresOnline): void
    {
        $this->requiresOnline = $requiresOnline;
    }
}
