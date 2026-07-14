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
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\BedRepository;
use OswisOrg\OswisCalendarBundle\State\AccommodationApiProcessor;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Jednotlivé lůžko v ubytovací jednotce (per-bed granularita — Q8.2→B). Umožňuje přesnost:
 * MANŽELSKÁ POSTEL = dvě lůžka spárovaná přes `pairedWith`; PŘISTÝLKA = `bedType` = extra_bed.
 *
 * Serializace: serialization/Accommodation/Bed.yaml. `unit`/`pairedWith` IRI resolvuje AccommodationApiProcessor.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_beds_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_bed_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_beds_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: AccommodationApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_bed_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_bed_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: AccommodationApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['unit.id', 'bedType'])]
#[Entity(repositoryClass: BedRepository::class)]
#[Table(name: 'calendar_accommodation_bed')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class Bed implements BasicInterface
{
    use BasicTrait;

    public const string TYPE_SINGLE = 'single';
    public const string TYPE_DOUBLE = 'double';
    public const string TYPE_EXTRA = 'extra_bed';
    public const string TYPE_BUNK_TOP = 'bunk_top';
    public const string TYPE_BUNK_BOTTOM = 'bunk_bottom';

    /** @var list<string> */
    public const array ALLOWED_TYPES = [
        self::TYPE_SINGLE,
        self::TYPE_DOUBLE,
        self::TYPE_EXTRA,
        self::TYPE_BUNK_TOP,
        self::TYPE_BUNK_BOTTOM,
    ];

    #[ManyToOne(targetEntity: AccommodationUnit::class, inversedBy: 'beds')]
    #[JoinColumn(name: 'unit_id', nullable: false)]
    #[Assert\NotNull]
    #[MaxDepth(1)]
    protected ?AccommodationUnit $unit = null;

    /** Popisek („lůžko #1", „postel u okna", „přistýlka"). */
    #[Column(type: 'string', length: 64, nullable: true)]
    protected ?string $label = null;

    #[Column(type: 'string', length: 16)]
    #[Assert\Choice(choices: self::ALLOWED_TYPES)]
    protected string $bedType = self::TYPE_SINGLE;

    /** Spárované lůžko = manželská postel (2 lůžka jako pár). Self-reference, nullable. */
    #[ManyToOne(targetEntity: self::class)]
    #[JoinColumn(name: 'paired_with_id', nullable: true, onDelete: 'SET NULL')]
    #[MaxDepth(1)]
    protected ?Bed $pairedWith = null;

    public function __construct(?string $label = null, string $bedType = self::TYPE_SINGLE)
    {
        $this->label = $label;
        $this->setBedType($bedType);
    }

    public function getUnit(): ?AccommodationUnit
    {
        return $this->unit;
    }

    public function setUnit(?AccommodationUnit $unit): void
    {
        $this->unit = $unit;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getBedType(): string
    {
        return $this->bedType;
    }

    public function setBedType(string $bedType): void
    {
        if (!in_array($bedType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Neplatný typ lůžka: '$bedType'.");
        }
        $this->bedType = $bedType;
    }

    public function getPairedWith(): ?Bed
    {
        return $this->pairedWith;
    }

    public function setPairedWith(?Bed $pairedWith): void
    {
        $this->pairedWith = $pairedWith;
    }

    public function isExtraBed(): bool
    {
        return self::TYPE_EXTRA === $this->bedType;
    }
}
