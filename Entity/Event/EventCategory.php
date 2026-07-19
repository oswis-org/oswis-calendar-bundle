<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
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
use Doctrine\ORM\Mapping\Table;
use InvalidArgumentException;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;

/**
 * Category (type) of event.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_MANAGER')",
            normalizationContext: ['groups' => ['calendar_event_categories_get']],
        ),
        new Post(
            security: "is_granted('ROLE_MANAGER')",
            denormalizationContext: ['groups' => ['calendar_event_categories_post']]
        ),
        new Get(
            security: "is_granted('ROLE_MANAGER')",
            normalizationContext: ['groups' => ['calendar_event_category_get']],
        ),
        new Put(
            security: "is_granted('ROLE_MANAGER')",
            denormalizationContext: ['groups' => ['calendar_event_category_put']]
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['calendar_event_category_delete']]
        ),
    ],
    security: "is_granted('ROLE_MANAGER')",
    filters: ["search"]
)]
#[ApiFilter(OrderFilter::class)]
#[Entity]
#[Table(name: 'calendar_event_category')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class EventCategory implements NameableInterface
{
    use NameableTrait;
    use ColorTrait;
    use TypeTrait;

    /** Předvyplněný čas začátku aktivity této kategorie (jen čas; přepsatelné per aktivita). */
    #[Column(type: 'time', nullable: true)]
    protected ?DateTimeInterface $defaultStartTime = null;

    /** Předvyplněný čas konce aktivity této kategorie (jen čas; když <= start, jde o přes-půlnoční rozsah). */
    #[Column(type: 'time', nullable: true)]
    protected ?DateTimeInterface $defaultEndTime = null;

    /** Default veřejnosti aktivit této kategorie (služby neveřejné, běžné aktivity veřejné); přepsatelné per aktivita. */
    #[Column(type: 'boolean', options: ['default' => false])]
    protected bool $defaultPublic = false;

    public const YEAR_OF_EVENT = 'year-of-event';
    public const BATCH_OF_EVENT = 'batch-of-event';
    public const LECTURE = 'lecture';
    public const WORKSHOP = 'workshop';
    public const MODERATED_DISCUSSION = 'moderated-discussion';
    public const TRANSPORT = 'transport';
    public const TEAM_BUILDING_STAY = 'team-building-stay';
    public const TEAM_BUILDING = 'team-building';
    public const EVIDENCE = 'evidence';
    public const SPORT = 'sport';
    public const FOOD = 'food';
    /** Nadřazený „blok" programu (nadakce) — sdružuje pod-aktivity (rotace/série); čas se odvozuje z pod-akcí. */
    public const PROGRAM_BLOCK = 'program-block';
    /** Veřejný program — další typy aktivit (viditelné účastníkovi v appce). */
    public const CEREMONY = 'ceremony';          // Ceremoniál (zahájení/zakončení)
    public const FREE_TIME = 'free-time';        // Volný program / pauza
    public const EVENING_PROGRAM = 'evening-program'; // Večerní program / party
    /** Služby (rozpis SLUŽEB) — kdo má kdy směnu na daném stanovišti. */
    public const SERVICE_STEERING = 'service-steering'; // Řízení
    public const SERVICE_CALLING = 'service-calling';   // Svolávání
    public const SERVICE_CANTEEN = 'service-canteen';   // Jídelna
    public const SERVICE_BAR = 'service-bar';           // Stolárna
    public const SERVICE_KIOSK = 'service-kiosk';       // Kiosek
    public const SERVICE_MEDIC = 'service-medic';       // Zdravotník
    public const SERVICE_CHECKOUT = 'service-checkout'; // Check-out (odjezdové úkony)
    public const SERVICE_NIGHT_WATCH = 'service-night-watch'; // Noční hlídka
    public const SERVICE_CLEANING = 'service-cleaning'; // Úklid
    public const SERVICE_TECH = 'service-tech';         // Technika / AV
    public const SERVICE_PHOTO = 'service-photo';       // Foto / dokumentace

    /** Denní služby (rozpis SLUŽEB) — člen týmu má směnu na daném stanovišti v daném čase. */
    public const SERVICE_TYPES
        = [
            self::SERVICE_STEERING,
            self::SERVICE_CALLING,
            self::SERVICE_CANTEEN,
            self::SERVICE_BAR,
            self::SERVICE_KIOSK,
            self::SERVICE_MEDIC,
            self::SERVICE_CHECKOUT,
            self::SERVICE_NIGHT_WATCH,
            self::SERVICE_CLEANING,
            self::SERVICE_TECH,
            self::SERVICE_PHOTO,
        ];
    public const ALLOWED_TYPES
        = [
            self::YEAR_OF_EVENT,
            self::BATCH_OF_EVENT,
            self::LECTURE,
            self::WORKSHOP,
            self::MODERATED_DISCUSSION,
            self::TRANSPORT,
            self::TEAM_BUILDING_STAY,
            self::TEAM_BUILDING,
            self::EVIDENCE,
            self::SPORT,
            self::FOOD,
            self::PROGRAM_BLOCK,
            self::CEREMONY,
            self::FREE_TIME,
            self::EVENING_PROGRAM,
            self::SERVICE_STEERING,
            self::SERVICE_CALLING,
            self::SERVICE_CANTEEN,
            self::SERVICE_BAR,
            self::SERVICE_KIOSK,
            self::SERVICE_MEDIC,
            self::SERVICE_CHECKOUT,
            self::SERVICE_NIGHT_WATCH,
            self::SERVICE_CLEANING,
            self::SERVICE_TECH,
            self::SERVICE_PHOTO,
        ];

    /**
     * @param Nameable|null $nameable
     * @param string|null $type
     * @param string|null $color
     *
     * @throws InvalidArgumentException
     */
    public function __construct(?Nameable $nameable = null, ?string $type = null, ?string $color = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
        $this->setColor($color);
    }

    public static function getAllowedTypesDefault(): array
    {
        return self::ALLOWED_TYPES;
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    public static function isServiceType(?string $type): bool
    {
        return null !== $type && in_array($type, self::SERVICE_TYPES, true);
    }

    public function getDefaultStartTime(): ?DateTimeInterface
    {
        return $this->defaultStartTime;
    }

    public function setDefaultStartTime(?DateTimeInterface $defaultStartTime): void
    {
        $this->defaultStartTime = $defaultStartTime;
    }

    public function getDefaultEndTime(): ?DateTimeInterface
    {
        return $this->defaultEndTime;
    }

    public function setDefaultEndTime(?DateTimeInterface $defaultEndTime): void
    {
        $this->defaultEndTime = $defaultEndTime;
    }

    public function isDefaultPublic(): bool
    {
        return $this->defaultPublic;
    }

    public function setDefaultPublic(bool $defaultPublic): void
    {
        $this->defaultPublic = $defaultPublic;
    }
}
