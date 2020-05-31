<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use InvalidArgumentException;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;
use function in_array;

/**
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantTypeRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_type")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_types_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_types_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_type_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_type_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_participant_type_delete"}}
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
class ParticipantType implements NameableInterface
{
    use NameableTrait;
    use EntityPublicTrait;
    use TypeTrait;

    public const TYPE_ATTENDEE = 'attendee';
    public const TYPE_ORGANIZER = 'organizer';
    public const TYPE_STAFF = 'staff';
    public const TYPE_PARTNER = 'partner';
    public const TYPE_GUEST = 'guest';
    public const TYPE_MANAGER = 'manager';
    public const MANAGEMENT_TYPES = [self::TYPE_MANAGER];

    /**
     * Send formal (or informal) e-mails?
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $formal = null;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null  $nameable
     * @param string|null    $type
     * @param bool|null      $formal
     * @param Publicity|null $publicity
     *
     * @throws InvalidArgumentException
     */
    public function __construct(?Nameable $nameable = null, ?string $type = null, ?bool $formal = true, Publicity $publicity = null)
    {
        $this->setType($type);
        $this->setFormal($formal);
        $this->setFieldsFromNameable($nameable);
        $this->setFieldsFromPublicity($publicity);
    }

    public function setFormal(?bool $formal): void
    {
        $this->formal = $formal ?? false;
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            self::TYPE_ATTENDEE, // Attendee of event.
            self::TYPE_ORGANIZER, // Organization/department/person who organizes event.
            self::TYPE_STAFF, // Somebody who works (is member of realization team) in event.
            self::TYPE_PARTNER, // Somebody (organization) who supports event.
            self::TYPE_GUEST, // Somebody who performs at the event.
            self::TYPE_MANAGER, // Somebody who manages the event.
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    public function isFormal(): bool
    {
        return (bool)$this->formal;
    }

    public function isManager(): bool
    {
        return in_array($this->getType(), self::MANAGEMENT_TYPES, true);
    }
}
