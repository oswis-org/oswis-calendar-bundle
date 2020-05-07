<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use OswisOrg\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlag;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_flag")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_flags_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_flags_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_flag_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_flag_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_flag_delete"}}
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
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventFlag extends AbstractEventFlag
{
    public function __construct(?Nameable $nameable = null)
    {
        $this->setFieldsFromNameable($nameable);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }
}
