<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;

/**
 * Some category (type) of participant flags.
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_flag_category")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_flag_categories_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_flag_categories_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_flag_category_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_flag_category_put"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "shortName",
 *     "description",
 *     "note",
 *     "internalNote",
 *     "type",
 *     "color"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_flag")
 */
class FlagCategory
{
    use NameableTrait;
    use ColorTrait;
    use TypeTrait;

    public const TYPE_FOOD = 'food';
    public const TYPE_TRANSPORT = 'transport';
    public const TYPE_T_SHIRT_SIZE = 't-shirt-size';
    public const TYPE_T_SHIRT_HANDED_OVER = 't-shirt-handed-over';
    public const TYPE_ACCOMMODATION_TYPE = 'accommodation-type';
    public const TYPE_ARRIVED = 'arrived';
    public const TYPE_LEFT = 'left';
    public const TYPE_PARTNER_HOMEPAGE = 'partner-homepage';
    public const TYPE_SCHOOL = 'school';

    /**
     * @param Nameable|null $nameable
     * @param string|null   $type
     * @param string|null   $color
     *
     * @throws InvalidTypeException
     */
    public function __construct(?Nameable $nameable = null, ?string $type = null, ?string $color = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
        $this->setColor($color);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            self::TYPE_FOOD,
            self::TYPE_TRANSPORT,
            self::TYPE_T_SHIRT_SIZE,
            self::TYPE_T_SHIRT_HANDED_OVER,
            self::TYPE_ACCOMMODATION_TYPE,
            self::TYPE_ARRIVED,
            self::TYPE_LEFT,
            self::TYPE_PARTNER_HOMEPAGE,
            self::TYPE_SCHOOL,
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }
}
