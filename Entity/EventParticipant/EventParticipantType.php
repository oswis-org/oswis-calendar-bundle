<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\EntityPublicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TypeTrait;
use function in_array;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_type")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_types_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_types_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_type_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_type_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_type_delete"}}
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
class EventParticipantType
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use EntityPublicTrait;
    use TypeTrait;

    public const TYPE_ATTENDEE = 'attendee';
    public const TYPE_ORGANIZER = 'organizer';
    public const TYPE_STAFF = 'staff';
    public const TYPE_SPONSOR = 'sponsor';
    public const TYPE_GUEST = 'guest';
    public const TYPE_MANAGER = 'manager';
    public const MANAGEMENT_TYPES = [self::TYPE_MANAGER];

    /**
     * Send formal (or informal) e-mails?
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected ?bool $formal = null;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null   $type
     * @param bool|null     $formal
     *
     * @throws InvalidArgumentException
     */
    public function __construct(?Nameable $nameable = null, ?string $type = null, ?bool $formal = true)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
        $this->setFormal($formal);
    }

    final public function setFormal(?bool $formal): void
    {
        $this->formal = $formal ?? false;
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            self::TYPE_ATTENDEE, // Attendee of event.
            self::TYPE_ORGANIZER, // Organization/department/person who organizes event.
            self::TYPE_STAFF, // Somebody who works (is member of realization team) in event.
            self::TYPE_SPONSOR, // Somebody (organization) who supports event.
            self::TYPE_GUEST, // Somebody who performs at the event.
            self::TYPE_MANAGER, // Somebody who manages the event.
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    /**
     * @return bool
     */
    final public function isFormal(): bool
    {
        return $this->formal ?? false;
    }

    final public function isManager(): bool
    {
        return in_array($this->getType(), self::MANAGEMENT_TYPES, true);
    }
}
