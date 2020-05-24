<?php /** @noinspection PhpUnused */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlagType;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_flag_type")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_flag_types_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_types_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_flag_type_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_type_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_flag_type_delete"}}
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
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantFlagType extends AbstractEventFlagType
{
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
     * @param int|null      $minFlagsAllowed
     * @param int|null      $maxFlagsAllowed
     *
     * @throws InvalidArgumentException
     */
    public function __construct(?Nameable $nameable = null, ?string $type = null, ?int $minFlagsAllowed = null, ?int $maxFlagsAllowed = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setMinInParticipant($minFlagsAllowed);
        $this->setMaxInParticipant($maxFlagsAllowed);
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
