<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use InvalidArgumentException;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TypeTrait;
use function assert;

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_type_revision")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventTypeRevision extends AbstractRevision
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use ColorTrait;
    use TypeTrait;

    public const YEAR_OF_EVENT = 'year-of-event';
    public const BATCH_OF_EVENT = 'batch-of-event';
    public const LECTURE = 'lecture';
    public const WORKSHOP = 'workshop';
    public const MODERATED_DISCUSSION = 'moderated-discussion';
    public const TRANSPORT = 'transport';
    public const TEAM_BUILDING_STAY = 'team-building-stay';
    public const TEAM_BUILDING = 'team-building';

    /**
     * @var AbstractRevisionContainer|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\EventType",
     *     fetch="EAGER",
     *     inversedBy="revisions"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="container_id", referencedColumnName="id")
     */
    protected ?AbstractRevisionContainer $container;

    /**
     * EventRevision constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null   $type
     * @param string|null   $color
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?string $type = null,
        ?string $color = null
    ) {
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
        $this->setColor($color);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            self::YEAR_OF_EVENT,
            self::BATCH_OF_EVENT,
            self::LECTURE,
            self::WORKSHOP,
            self::MODERATED_DISCUSSION,
            self::TRANSPORT,
            self::TEAM_BUILDING_STAY,
            self::TEAM_BUILDING,
        ];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }

    /**
     * @return string
     */
    public static function getRevisionContainerClassName(): string
    {
        return EventType::class;
    }

    /**
     * @param AbstractRevisionContainer|null $revision
     */
    public static function checkRevisionContainer(?AbstractRevisionContainer $revision): void
    {
        assert($revision instanceof EventType);
    }
}
