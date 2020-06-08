<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_category_category")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_category_categories_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_category_categories_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_category_category_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_category_category_put"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "description",
 *     "note"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class RegistrationFlagCategory extends AbstractEventFlagCategory
{
    use NameableTrait;
    use TypeTrait;

    public const TYPE_FOOD = 'food';
    public const TYPE_TRANSPORT = 'transport';
    public const TYPE_T_SHIRT = 't-shirt';
    public const TYPE_ACCOMMODATION_TYPE = 'accommodation-type';

    /**
     * @var string Flag of this type says that partner might be rendered on homepage of web.
     */
    public const TYPE_PARTNER_HOMEPAGE = 'partner-homepage';

    /**
     * @param Nameable|null $nameable
     * @param string|null   $type
     *
     * @throws InvalidTypeException
     */
    public function __construct(?Nameable $nameable = null, ?string $type = null) {
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [self::TYPE_FOOD, self::TYPE_TRANSPORT, self::TYPE_ACCOMMODATION_TYPE, self::TYPE_T_SHIRT, self::TYPE_PARTNER_HOMEPAGE];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }
}
