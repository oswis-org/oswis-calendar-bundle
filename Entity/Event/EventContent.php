<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractWebContent;

#[ApiResource(
    operations: [
        new GetCollection(
            security: 'is_granted("ROLE_MANAGER")',
            normalizationContext: [
                'groups' => ['entities_get', 'calendar_event_contents_get'],
                'enable_max_depth' => true,
            ]
        ),
        new Post(
            security: 'is_granted("ROLE_MANAGER")',
            denormalizationContext: [
                'groups' => ['entities_post', 'calendar_event_contents_post'],
                'enable_max_depth' => true,
            ]
        ),
        new Get(
            security: 'is_granted("ROLE_MANAGER")',
            normalizationContext: [
                'groups' => ['entity_get', 'calendar_event_content_get'],
                'enable_max_depth' => true,
            ]
        ),
        new Put(
            security: 'is_granted("ROLE_MANAGER")',
            denormalizationContext: [
                'groups' => ['entity_put', 'calendar_event_content_put'],
                'enable_max_depth' => true,
            ]
        ),
    ],
    filters: ['search'],
    security: 'is_granted("ROLE_MANAGER")'
)]
#[Entity]
#[Table(name: 'calendar_event_content')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class EventContent extends AbstractWebContent
{
    #[ManyToOne(targetEntity: Event::class, fetch: 'EAGER', inversedBy: 'contents')]
    #[JoinColumn(nullable: true)]
    protected ?Event $event = null;

    /**
     * @param \OswisOrg\OswisCalendarBundle\Entity\Event\Event|null $event
     * @param string|null $textValue
     * @param string|null $type
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        ?Event $event = null,
        ?string $textValue = null,
        ?string $type = null,
    )
    {
        parent::__construct($textValue, $type);
        $this->event = $event;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }
}
