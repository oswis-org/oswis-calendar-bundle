<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use InvalidArgumentException;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TextValueTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TypeTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_web_content")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventWebContent
{
    public const HTML = 'html';
    public const CSS = 'css';
    public const JS = 'js';

    use BasicEntityTrait;
    use TypeTrait;
    use TextValueTrait;

    /**
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="eventWebContents"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * EventWebContent constructor.
     *
     * @param Event|null  $event
     * @param string|null $textValue
     * @param string|null $type
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        ?Event $event = null,
        ?string $textValue = null,
        ?string $type = null
    ) {
        $this->setEvent($event);
        $this->setType($type);
        $this->setTextValue($textValue);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            self::HTML,
            self::CSS,
            self::JS,
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * @param Event|null $event
     *
     * @throws InvalidArgumentException
     */
    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeWebContent($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventWebContent($this);
        }
    }
}
