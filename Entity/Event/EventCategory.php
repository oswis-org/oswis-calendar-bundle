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
use Doctrine\ORM\Mapping\Cache;
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
}
