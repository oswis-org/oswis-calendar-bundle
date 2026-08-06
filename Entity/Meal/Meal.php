<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Meal;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Meal\MealRepository;
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Jedno jídlo jednoho dne turnusu — položka jídelníčku.
 *
 * **Proč vlastní entita a ne programová aktivita:** jídla v programu nejsou a nebudou. Ověřeno
 * v datech 2026-08-06: v naimportovaném programu 2026 není ani jeden oběd či snídaně (jediná
 * `vecere` je z roku 2022) a `ImportProgramCommand` u jídel sám píše, že se na ně nikdo nehlásí.
 * Navíc program na produkci vůbec není. Kuchyň i účastník přitom přemýšlí v osách **den × jídlo**,
 * ne „položka harmonogramu" — takže kotvou je `event` + `date` + `type`, nezávisle na programu.
 * (Souvisí s pravidlem [[reference_event_is_only_happenings]]: provozní data se do Eventu necpou.)
 *
 * **Varianty:** jídlo má 0–N variant ({@see MealVariant}). Žádná varianta = čistě informativní
 * jídelníček (snídaně formou bufetu). Jedna a víc = z čeho se vybírá; samotný výběr účastníka je
 * samostatná vrstva (vize B7) a záměrně tu ještě není — model je na ni ale připravený, aby to
 * nebyla přestavba.
 *
 * ⚠️ `date` je ZÁMĚRNĚ datum, ne datetime: jídelníček se plánuje po dnech. Čas výdeje je nepovinný
 * (`servedFrom`/`servedTo`) a mění se na místě častěji než menu samo.
 */
#[ApiResource(
    operations: [
        // Čtení bez obecné grupy `entities_get` — ta přitáhne slug/note/createdBy a rozbalí
        // vnořený turnus i s autory. Poučeno z nástěnky (5200 B → 950 B) a kolekce akcí.
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_meals_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar_meal_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        // ⚠️ `processor` POVINNĚ: výchozí denormalizér neresolvuje IRI relací, postaví prázdný
        // Event a Doctrine spadne na „A new entity was found through the relationship".
        // Tatáž past už stála za 500 u nástěnky. [[reference_apiplatform_relation_iri_on_post]]
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_meals_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_meal_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_meal_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['event' => 'exact', 'type' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['date'])]
#[ApiFilter(OrderFilter::class, properties: ['date', 'type'])]
#[Entity(repositoryClass: MealRepository::class)]
#[Table(name: 'calendar_meal')]
// Jeden turnus + den + typ = právě jedno jídlo. Bez toho by dvojí uložení formuláře udělalo
// dva obědy na týž den a účastníkovi by se jídelníček zdvojil.
// ⚠️ Constraint MUSÍ být i v migraci — a naopak; jinak `doctrine:schema:validate` zrezaví.
#[UniqueConstraint(name: 'meal_event_date_type_uniq', columns: ['event_id', 'date', 'type'])]
#[Index(name: 'meal_event_date_idx', columns: ['event_id', 'date'])]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_meal')]
class Meal implements NameableInterface
{
    public const string TYPE_BREAKFAST = 'breakfast';
    public const string TYPE_LUNCH = 'lunch';
    public const string TYPE_DINNER = 'dinner';
    public const string TYPE_SNACK = 'snack';

    /**
     * Pořadí typů během dne. Slouží k řazení (v SQL by `type` řadil abecedně, tzn. dinner před
     * lunch) i k validaci — seznam povolených hodnot je odvozený odsud, aby nemohly rozejít.
     *
     * @var array<string, int>
     */
    public const array TYPE_ORDER = [
        self::TYPE_BREAKFAST => 1,
        self::TYPE_LUNCH => 2,
        self::TYPE_SNACK => 3,
        self::TYPE_DINNER => 4,
    ];

    use NameableTrait;
    use TextValueTrait;
    use DeletedTrait;

    /**
     * Turnus, ke kterému jídelníček patří. Nastavuje se SETTEREM, ne konstruktorem — API Platform
     * resolvuje IRI relace jen přes setter ([[reference_apiplatform_relation_iri_on_post]]).
     */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Event $event = null;

    /** Den, na který jídlo připadá. */
    #[Column(name: 'date', type: 'date', nullable: false)]
    #[Assert\NotNull]
    protected ?DateTimeInterface $date = null;

    /** Snídaně / oběd / svačina / večeře. */
    #[Column(name: 'type', type: 'string', length: 16, nullable: false)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'types'])]
    protected ?string $type = null;

    /** Začátek výdeje; null = neurčeno (řeší se na místě). */
    #[Column(name: 'served_from', type: 'time', nullable: true)]
    protected ?DateTimeInterface $servedFrom = null;

    /** Konec výdeje; null = neurčeno. */
    #[Column(name: 'served_to', type: 'time', nullable: true)]
    protected ?DateTimeInterface $servedTo = null;

    /**
     * Varianty k výběru. Prázdné = jídelníček je jen informativní.
     *
     * @var Collection<int, MealVariant>
     */
    #[OneToMany(mappedBy: 'meal', targetEntity: MealVariant::class, cascade: ['persist'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    protected Collection $variants;

    public function __construct(?Nameable $nameable = null, ?string $textValue = null)
    {
        $this->variants = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setTextValue($textValue);
    }

    /**
     * Povolené typy — jediný zdroj pravdy je {@see TYPE_ORDER}.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::TYPE_ORDER);
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    public function getDate(): ?DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(?DateTimeInterface $date): void
    {
        $this->date = $date;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    /**
     * Pořadí jídla v rámci dne (snídaně → oběd → svačina → večeře).
     * Nevyplněný nebo neznámý typ padá na konec, ať neshazuje řazení.
     */
    public function getTypeOrder(): int
    {
        return null === $this->type ? PHP_INT_MAX : (self::TYPE_ORDER[$this->type] ?? PHP_INT_MAX);
    }

    public function getServedFrom(): ?DateTimeInterface
    {
        return $this->servedFrom;
    }

    public function setServedFrom(?DateTimeInterface $servedFrom): void
    {
        $this->servedFrom = $servedFrom;
    }

    public function getServedTo(): ?DateTimeInterface
    {
        return $this->servedTo;
    }

    public function setServedTo(?DateTimeInterface $servedTo): void
    {
        $this->servedTo = $servedTo;
    }

    /** @return Collection<int, MealVariant> */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(?MealVariant $variant): void
    {
        if (null !== $variant && !$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setMeal($this);
        }
    }

    public function removeVariant(?MealVariant $variant): void
    {
        if (null !== $variant && $this->variants->removeElement($variant)) {
            $variant->setMeal(null);
        }
    }

    /** Vybírá se z čeho? Prázdné varianty = jen informace, ne volba. */
    public function hasChoice(): bool
    {
        return $this->variants->count() > 1;
    }
}
