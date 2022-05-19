<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use InvalidArgumentException;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;

/**
 * Category (type) of event.
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_category")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_categories_get"}},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_categories_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_category_get"}},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_category_put"}}
 *     },
 *     "delete"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_category_delete"}}
 *     }
 *   }
 * )
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter::class)
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "name",
 *     "description",
 *     "note"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
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
        ];

    /**
     * @param  Nameable|null  $nameable
     * @param  string|null  $type
     * @param  string|null  $color
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
