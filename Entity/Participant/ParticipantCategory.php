<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantCategoryRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;
use function in_array;

/**
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "name",
 *     "description",
 *     "note"
 * })
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_categories_get"}},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_categories_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_category_get"}},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_category_put"}}
 *     }
 *   }
 * )
 */
#[Entity(repositoryClass: ParticipantCategoryRepository::class)]
#[Table(name: 'calendar_participant_category')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
#[ApiFilter(OrderFilter::class)]
class ParticipantCategory implements NameableInterface
{
    use NameableTrait;
    use TypeTrait;

    public const TYPE_ATTENDEE = 'attendee';
    public const TYPE_ORGANIZER = 'organizer';
    public const TYPE_TEAM_MEMBER = 'team-member';
    public const TYPE_STAFF = 'staff';
    public const TYPE_PARTNER = 'partner';
    public const TYPE_GUEST = 'guest';
    public const TYPE_MANAGER = 'manager';
    public const MANAGEMENT_TYPES = [self::TYPE_MANAGER];

    public const ALLOWED_TYPES
        = [
            self::TYPE_ATTENDEE,  // Attendee of event.
            self::TYPE_ORGANIZER, // Organization/department/person who organizes event.
            self::TYPE_STAFF,     // Somebody who works (is member of realization team) in event.
            self::TYPE_PARTNER,   // Somebody (organization) who supports event.
            self::TYPE_GUEST,     // Somebody who performs at the event.
            self::TYPE_MANAGER,   // Somebody who manages the event.
            self::TYPE_TEAM_MEMBER,
        ];

    /** Send formal (or informal) e-mails? */
    #[Column(type: 'boolean', nullable: true)]
    protected ?bool $formal = null;

    /**
     * EmployerFlag constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null $type
     * @param bool|null $formal
     *
     * @throws InvalidArgumentException
     */
    public function __construct(?Nameable $nameable = null, ?string $type = null, ?bool $formal = true)
    {
        $this->setType($type);
        $this->setFormal($formal);
        $this->setFieldsFromNameable($nameable);
    }

    public function setFormal(?bool $formal): void
    {
        $this->formal = $formal;
    }

    public static function getAllowedTypesDefault(): array
    {
        return self::ALLOWED_TYPES;
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    public function isDefaultType(): bool
    {
        return self::getDefaultCategoryType() === $this->getType();
    }

    public static function getDefaultCategoryType(): ?string
    {
        return self::TYPE_ATTENDEE;
    }

    public function isFormal(): ?bool
    {
        return $this->formal;
    }

    public function isManager(): bool
    {
        return in_array($this->getType(), self::MANAGEMENT_TYPES, true);
    }
}
