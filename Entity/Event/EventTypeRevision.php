<?php

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use function assert;
use function in_array;

class EventTypeRevision extends AbstractRevision
{

    public const ALLOWED_TYPES_DEFAULT = [
        'year-of-event',
        'batch-of-event',
        'lecture',
        'workshop',
        'moderated-discussion',
        'transport',
        'team-building-stay',
        'team-building',
    ];

    public const ALLOWED_TYPES_CUSTOM = [];

    /**
     * @var Event
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event",
     *     inversedBy="revisions"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="container_id", referencedColumnName="id")
     */
    protected $container;

    /**
     * Type of this event.
     * @var string|null $type
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    private $type;

    use BasicEntityTrait;
    use NameableBasicTrait;
    use ColorTrait;

    /**
     * EventRevision constructor.
     *
     * @param Nameable|null $nameable
     * @param string|null   $type
     * @param string|null   $color
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

    /**
     * @param string|null $typeName
     *
     * @return bool
     * @throws InvalidArgumentException
     */
    final public static function checkType(?string $typeName): bool
    {
        if (in_array($typeName, self::getAllowedTypes(), true)) {
            return true;
        }
        throw new InvalidArgumentException('Typ události "'.$typeName.'" není povolen.');
    }

    final public static function getAllowedTypes(): array
    {
        return array_merge(self::ALLOWED_TYPES_DEFAULT, self::ALLOWED_TYPES_CUSTOM);
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

    /**
     * @return string|null
     */
    final public function getType(): ?string
    {
        /// TODO: Check type!!!
        return $this->type;
    }

    /**
     * @param string|null $type
     */
    final public function setType(?string $type): void
    {
        /// TODO: Check type!!
        $this->type = $type;
    }

}