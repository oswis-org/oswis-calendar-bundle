<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Gedmo\Mapping\Annotation\Uploadable;
use OswisOrg\OswisCalendarBundle\Controller\Event\EventImageAction;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractImage;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints\NotNull;
use Vich\UploaderBundle\Mapping\Annotation\UploadableField;

#[ApiResource(
    operations: [
        new Get(),
        new Post(
            uriTemplate: '/calendar_event_image',
            controller: EventImageAction::class,
            deserialize: false
        ),
    ]
)]
#[Uploadable]
#[Entity]
#[Table(name: 'calendar_event_image')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event_image')]
class EventImage extends AbstractImage
{
    public const TYPE_LEAFLET = 'leaflet';
    public const TYPE_IMAGE = 'image';
    public const TYPE_SOCIAL = 'social';
    public const TYPE_MAP = 'map';
    public const TYPE_POSTER = 'poster';
    public const TYPE_GALLERY = 'gallery';
    use BasicTrait;
    use TypeTrait;
    use PriorityTrait;
    use EntityPublicTrait;

    #[UploadableField(mapping: 'calendar_event_image', fileNameProperty: 'contentName', mimeType: 'contentMimeType')]
    #[NotNull]
    public ?File $file = null;

    #[ManyToOne(targetEntity: Event::class, cascade: ['all'], inversedBy: 'images')]
    #[JoinColumn(name: 'event_id', referencedColumnName: 'id')]
    protected ?Event $event = null;

    /**
     * @param File|null   $file
     * @param string|null $type
     * @param int|null    $priority
     * @param Publicity|null $publicity
     *
     * @throws InvalidTypeException
     */
    public function __construct(
        ?File $file = null,
        ?string $type = null,
        ?int $priority = null,
        ?Publicity $publicity = null
    ) {
        $this->setFile($file);
        $this->setType($type);
        $this->setPriority($priority);
        $this->setFieldsFromPublicity($publicity);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [
            self::TYPE_LEAFLET,
            self::TYPE_IMAGE,
            self::TYPE_SOCIAL,
            self::TYPE_MAP,
            self::TYPE_POSTER,
            self::TYPE_GALLERY,
        ];
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        if (null !== $this->event && $event !== $this->event) {
            $this->event->removeImage($this);
        }
        $this->event = $event;
    }
}
