<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;

/**
 * Some category (type) of participant flags.
 * @@OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "name",
 *     "shortName",
 *     "description",
 *     "note",
 *     "internalNote",
 *     "type",
 *     "color"
 * })
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_participant_flag_categories_get']],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['calendar_participant_flag_categories_post']],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar_participant_flag_category_get']],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Put(
            denormalizationContext: ['groups' => ['calendar_participant_flag_category_put']],
            security: "is_granted('ROLE_MANAGER')"
        ),
    ],
    filters: ['search'],
    security: "is_granted('ROLE_MANAGER')"
)]
#[Entity]
#[Table(name: 'calendar_flag_category')]
#[ApiFilter(OrderFilter::class)]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_flag')]
class RegistrationFlagCategory implements NameableInterface
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
    public const TYPE_CONFIRMATIONS = 'confirmations';

    /**
     * @param Nameable|null $nameable
     * @param string|null $type
     * @param string|null $color
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
            self::TYPE_CONFIRMATIONS,
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }
}
