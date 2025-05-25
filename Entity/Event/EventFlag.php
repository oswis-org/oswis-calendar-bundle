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
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "name",
 *     "description",
 *     "note"
 * })
 */
#[Entity]
#[Table(name: 'calendar_event_flag')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
#[ApiFilter(OrderFilter::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_event_flags_get']],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['calendar_event_flags_post']],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar_event_flag_get']],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Put(
            denormalizationContext: ['groups' => ['calendar_event_flag_put']],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Delete(
            denormalizationContext: ['groups' => ['calendar_event_flag_delete']],
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    filters: ['search'],
    security: "is_granted('ROLE_MANAGER')"
)]
class EventFlag
{
    use NameableTrait;
    use ColorTrait;

    public function __construct(?Nameable $nameable = null)
    {
        $this->setFieldsFromNameable($nameable);
    }
}
