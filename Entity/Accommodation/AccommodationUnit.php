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
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\AccommodationUnitRepository;
use OswisOrg\OswisCalendarBundle\State\AccommodationApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Serializer\Attribute\MaxDepth;

/**
 * Ubytovací jednotka — pokoj/chatka/motel-room/apartmán/stan. Nese kapacitu, typ, vlastnosti
 * ({@see AccommodationFeature}) a dočasnou nedostupnost (závada). Přiřazení účastníka =
 * {@see Reservation}.
 *
 * `facility` relace se resolvuje přes {@see AccommodationApiProcessor} (default denormalizer neresolvuje IRI).
 * Serializace: Resources/config/serialization/Accommodation/AccommodationUnit.yaml.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_accommodation_units_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_accommodation_unit_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_accommodation_units_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: AccommodationApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_accommodation_unit_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_accommodation_unit_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: AccommodationApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['facility.id', 'unitType'])]
#[Entity(repositoryClass: AccommodationUnitRepository::class)]
#[Table(name: 'calendar_accommodation_unit')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class AccommodationUnit implements NameableInterface, DeletedInterface
{
    use NameableTrait;
    use DeletedTrait;

    #[ManyToOne(targetEntity: Facility::class)]
    #[JoinColumn(name: 'facility_id', nullable: false)]
    #[MaxDepth(1)]
    protected ?Facility $facility = null;

    /** Typ jednotky (motel-room, chatka-bobik, mobilheim, stan, ubytovna…) — volný string per zařízení. */
    #[Column(type: 'string', length: 64, nullable: true)]
    protected ?string $unitType = null;

    /** Kapacita lůžek. */
    #[Column(type: 'integer')]
    protected int $capacity = 1;

    /** Dočasně nedostupná (závada) — nenabízet k přiřazení. */
    #[Column(type: 'boolean')]
    protected bool $temporarilyUnavailable = false;

    /** Patro / dostupnost („přízemí", „1. patro", „po schodech nahoru"). */
    #[Column(type: 'string', length: 32, nullable: true)]
    protected ?string $floor = null;

    /** Cenová šablona (jen celoroční booking — Seznamovák nepoužije; model připraven). */
    #[ManyToOne(targetEntity: PricingTemplate::class)]
    #[JoinColumn(name: 'pricing_template_id', nullable: true, onDelete: 'SET NULL')]
    #[MaxDepth(1)]
    protected ?PricingTemplate $pricingTemplate = null;

    /** @var Collection<int, AccommodationFeature> */
    #[ManyToMany(targetEntity: AccommodationFeature::class)]
    #[JoinTable(name: 'calendar_accommodation_unit_feature')]
    #[MaxDepth(1)]
    protected Collection $features;

    /** @var Collection<int, Bed> Jednotlivá lůžka (per-bed granularita). */
    #[OneToMany(targetEntity: Bed::class, mappedBy: 'unit', cascade: ['all'])]
    #[MaxDepth(1)]
    protected Collection $beds;

    public function __construct(
        ?Nameable $nameable = null,
        ?string $unitType = null,
        int $capacity = 1,
    ) {
        $this->setFieldsFromNameable($nameable);
        $this->unitType = $unitType;
        $this->capacity = $capacity;
        $this->features = new ArrayCollection();
        $this->beds = new ArrayCollection();
    }

    public function getFloor(): ?string
    {
        return $this->floor;
    }

    public function setFloor(?string $floor): void
    {
        $this->floor = $floor;
    }

    public function getPricingTemplate(): ?PricingTemplate
    {
        return $this->pricingTemplate;
    }

    public function setPricingTemplate(?PricingTemplate $pricingTemplate): void
    {
        $this->pricingTemplate = $pricingTemplate;
    }

    /** @return Collection<int, Bed> */
    public function getBeds(): Collection
    {
        return $this->beds;
    }

    public function getFacility(): ?Facility
    {
        return $this->facility;
    }

    public function setFacility(?Facility $facility): void
    {
        $this->facility = $facility;
    }

    public function getUnitType(): ?string
    {
        return $this->unitType;
    }

    public function setUnitType(?string $unitType): void
    {
        $this->unitType = $unitType;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): void
    {
        $this->capacity = max(0, $capacity);
    }

    public function isTemporarilyUnavailable(): bool
    {
        return $this->temporarilyUnavailable;
    }

    public function setTemporarilyUnavailable(bool $temporarilyUnavailable): void
    {
        $this->temporarilyUnavailable = $temporarilyUnavailable;
    }

    /** @return Collection<int, AccommodationFeature> */
    public function getFeatures(): Collection
    {
        return $this->features;
    }

    public function addFeature(AccommodationFeature $feature): void
    {
        if (!$this->features->contains($feature)) {
            $this->features->add($feature);
        }
    }

    public function removeFeature(AccommodationFeature $feature): void
    {
        $this->features->removeElement($feature);
    }

    public function hasFeatureCode(string $code): bool
    {
        foreach ($this->features as $feature) {
            if ($feature->getCode() === $code) {
                return true;
            }
        }

        return false;
    }
}
