<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Gedmo\Mapping\Annotation\Uploadable;
use OswisOrg\OswisCalendarBundle\Controller\Event\EventFileAction;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractFile;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TypeTrait;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints\NotNull;
use Vich\UploaderBundle\Mapping\Annotation\UploadableField;

#[ApiResource(collectionOperations: [
    'get',
    'post' => [
        'method' => 'POST',
        'path' => '/calendar_event_file',
        'controller' => EventFileAction::class,
        'defaults' => ['_api_receive' => false],
    ],
])]
#[Entity]
#[Table(name: 'calendar_event_file')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event_file')]
#[Uploadable]
class EventFile extends AbstractFile
{
    use BasicTrait;
    use TypeTrait;
    use PriorityTrait;
    use EntityPublicTrait;

    #[NotNull]
    #[UploadableField(mapping: 'calendar_event_file', fileNameProperty: 'contentName', mimeType: 'contentMimeType')]
    public ?File $file = null;

    #[ManyToOne(targetEntity: Event::class, inversedBy: 'files')]
    #[JoinColumn(name: 'event_id', referencedColumnName: 'id')]
    protected ?Event $event = null;

    /**
     * @param File|null $file
     * @param string|null $type
     * @param int|null $priority
     * @param Publicity|null $publicity
     *
     * @throws InvalidTypeException
     */
    public function __construct(
        ?File      $file = null,
        ?string    $type = null,
        ?int       $priority = null,
        ?Publicity $publicity = null
    )
    {
        $this->setFile($file);
        $this->setType($type);
        $this->setPriority($priority);
        $this->setFieldsFromPublicity($publicity);
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        if (null !== $this->event && $event !== $this->event) {
            $this->event->removeFile($this);
        }
        $this->event = $event;
    }
}
