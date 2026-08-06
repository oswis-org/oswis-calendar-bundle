<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Meal;

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
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Meal\MealVariantRepository;
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Jedna varianta jídla — „1) svíčková", „2) kuřecí", „3) vegetariánská".
 *
 * Samostatná entita, ne textové pole v {@see Meal}, ze dvou důvodů: vize B7 chce, aby si účastník
 * variantu **vybral** (volba musí na něco ukazovat), a kuchyň potřebuje **agregované počty per
 * varianta**. Obojí by z volného textu nešlo. Vrstva samotné volby tu ještě není — tohle je
 * základ, na kterém stojí.
 *
 * ⚠️ `allergens` je ZÁMĚRNĚ volný text, ne číselník: kuchyň je píše jako čísla podle vyhlášky
 * („1, 3, 7"), ale i slovy, a formát se rok od roku liší. Dietní OMEZENÍ účastníka je něco
 * jiného a bydlí jinde — `ParticipantFlag` typu `food` + `textValue` (vize B7); tohle je
 * vlastnost JÍDLA, ne člověka.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_meal_variants_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar_meal_variant_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        // ⚠️ Stejná past s IRI relací jako u Meal — bez procesoru 500 na uložení.
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_meal_variants_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_meal_variant_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_meal_variants_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['meal' => 'exact'])]
#[Entity(repositoryClass: MealVariantRepository::class)]
#[Table(name: 'calendar_meal_variant')]
#[Index(name: 'meal_variant_meal_idx', columns: ['meal_id'])]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_meal')]
class MealVariant implements NameableInterface
{
    use NameableTrait;
    use DeletedTrait;

    /**
     * Jídlo, ke kterému varianta patří. Setterem, ne konstruktorem — kvůli resolvování IRI.
     */
    #[ManyToOne(targetEntity: Meal::class, inversedBy: 'variants')]
    #[JoinColumn(name: 'meal_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Meal $meal = null;

    /** Pořadí ve výpisu — „1) …", „2) …". Nižší číslo dřív. */
    #[Column(name: 'position', type: 'integer', options: ['default' => 0])]
    protected int $position = 0;

    /** Alergeny tak, jak je píše kuchyň (volný text, viz poznámka u třídy). */
    #[Column(name: 'allergens', type: 'string', length: 255, nullable: true)]
    protected ?string $allergens = null;

    /**
     * Varianta vhodná pro bezmasou stravu. Nenahrazuje příznaky účastníka — je to jen vodítko
     * ve výpisu, aby vegetarián nemusel luštit název jídla.
     */
    #[Column(name: 'meat_free', type: 'boolean', options: ['default' => false])]
    protected bool $meatFree = false;

    public function __construct(?Nameable $nameable = null, int $position = 0)
    {
        $this->setFieldsFromNameable($nameable);
        $this->position = $position;
    }

    public function getMeal(): ?Meal
    {
        return $this->meal;
    }

    public function setMeal(?Meal $meal): void
    {
        $this->meal = $meal;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getAllergens(): ?string
    {
        return $this->allergens;
    }

    public function setAllergens(?string $allergens): void
    {
        $this->allergens = $allergens;
    }

    public function isMeatFree(): bool
    {
        return $this->meatFree;
    }

    public function setMeatFree(bool $meatFree): void
    {
        $this->meatFree = $meatFree;
    }
}
