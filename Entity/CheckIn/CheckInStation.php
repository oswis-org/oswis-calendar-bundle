<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\CheckIn;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
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
/*
 * Bez `OrderFilter` API Platform řadicí parametr TIŠE ignoruje — nevrátí chybu, jen se nic
 * nestane. Appka na tabletu přitom `order[orderNumber]=asc` posílá od začátku, takže hub
 * ukazoval příjezdovou linku v pořadí, které zrovna vrátila databáze. Ověřeno 26. 8. 2026:
 * `asc` i `desc` vracely identických 7 stanic ({@see \App\Tests\Functional\StaniceRazeniApiTest}).
 * `name` je tu jako druhý klíč — dvě stanice mohou mít stejné pořadí a shodu musí rozseknout
 * něco stálého, jinak se hub a web admin (ten řadí `pořadí, pak název`) rozejdou.
 */
#[ApiFilter(OrderFilter::class, properties: ['orderNumber', 'name'])]
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

    /** Strava — výdej stravenek dle pásky (per jídlo, plackovač). NENÍ příjezdové stanoviště. */
    public const string KIND_FOOD = 'food';

    /**
     * Zdravotník / diety — read-view dietních omezení a poznámek; dietáře vede rovnou za kuchařku.
     * Oddělené od {@see KIND_FOOD}: „food" byl přetížený (stravenky × dietní read-view) a na
     * příjezdu se řeší JEN tohle. Reálné stanoviště 2025 (rekonstrukce příjezdového dne §3).
     */
    public const string KIND_MEDIC = 'medic';

    /** Příjezdový balíček (merch) — výdej uvítacího balíčku. Reálné stanoviště 2025. */
    public const string KIND_WELCOME = 'welcome';

    /** Parkovací karty — řidiči dostanou kartu k bráně (týká se jen části lidí). */
    public const string KIND_PARKING = 'parking';

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
        self::KIND_MEDIC,
        self::KIND_TSHIRT,
        self::KIND_SAFETY,
        self::KIND_WELCOME,
        self::KIND_PARKING,
        self::KIND_GENERIC,
    ];

    /**
     * Druhy, které NEsmí být „za branou" evidence — tj. samy tvoří vstup do pipeline.
     * Ostatní stoly zásadně vidí jen ty, co prošli evidencí (závazná UX představa usera
     * 2026-06-12: „jen první stůl hledá v plném seznamu účastníků").
     */
    public const array ENTRY_KINDS = [
        self::KIND_EVIDENCE,
        self::KIND_PARKING,
    ];

    /**
     * Druhy, kterými NEPROCHÁZEJÍ všichni — jen ti, koho se to týká (user 2026-07-16:
     * „přes parkování jdou jen ti, co přijeli autem a chtějí parkovat přímo v areálu,
     * ostatní přes stanoviště neprocházejí").
     *
     * Důsledek pro UI: taková stanice NEMÁ jmenovatel. „3 / 110" a fronta „Čeká 107" by
     * lhaly — těch 107 tam nikdy nepřijde a vypadalo by to jako neodbavený zástup.
     * Místo poměru se ukazuje absolutní počet odbavených a stůl je hledací, ne odškrtávací.
     */
    public const array SELECTIVE_KINDS = [
        self::KIND_PARKING,
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
