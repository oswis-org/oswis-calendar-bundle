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
     * EventWebContent constructor.
     *
     * @param string|null $textValue
     * @param string|null $type
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        ?string $textValue = null,
        ?string $type = null
    ) {
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
}
