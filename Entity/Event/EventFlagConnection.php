<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
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
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_event_flag_connections_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_event_flag_connections_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_event_flag_connection_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Put(
            denormalizationContext: ['groups' => ['entity_put', 'calendar_event_flag_connection_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')"
        ),
    ],
    filters: ['search'],
    security: "is_granted('ROLE_MANAGER')"
)]
#[Entity]
#[Table(name: 'calendar_event_flag_connection')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class EventFlagConnection implements BasicInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DeletedTrait;

    #[ManyToOne(targetEntity: Event::class, fetch: 'EAGER', inversedBy: 'flagConnections')]
    #[JoinColumn(nullable: true)]
    protected ?Event $event = null;

    #[ManyToOne(targetEntity: EventFlag::class, fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    protected ?EventFlag $eventFlag = null;

    public function __construct(?EventFlag $eventFlag = null, ?string $textValue = null)
    {
        $this->setTextValue($textValue);
        $this->setEventFlag($eventFlag);
    }

    public function getEventFlag(): ?EventFlag
    {
        return $this->eventFlag;
    }

    public function setEventFlag(?EventFlag $eventFlag): void
    {
        $this->eventFlag = $eventFlag;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    public function isActive(): bool
    {
        return !$this->isDeleted();
    }
}
