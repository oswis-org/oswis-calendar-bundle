<?php

namespace Zakjakub\OswisCalendarBundle\Entity\MediaObjects;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use Symfony\Component\HttpFoundation\File\File;
use Zakjakub\OswisCalendarBundle\Controller\MediaObjects\CreateEventImageAction;

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_image")
 * @ApiResource(iri="http://schema.org/ImageObject", collectionOperations={
 *     "get",
 *     "post"={
 *         "method"="POST",
 *         "path"="/calendar_event_image",
 *         "controller"=CreateEventImageAction::class,
 *         "defaults"={"_api_receive"=false},
 *     },
 * })
 * @Vich\UploaderBundle\Mapping\Annotation\Uploadable()
 */
class EventImage
{

    /**
     * @Doctrine\ORM\Mapping\Id
     * @Doctrine\ORM\Mapping\GeneratedValue(strategy="AUTO")
     * @Doctrine\ORM\Mapping\Column(type="integer")
     */
    public $id;

    /**
     * @var File|null
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Vich\UploaderBundle\Mapping\Annotation\UploadableField(mapping="event_image", fileNameProperty="contentUrl")
     */
    public $file;

    /**
     * @var string|null
     * @Doctrine\ORM\Mapping\Column(nullable=true)
     * @ApiProperty(iri="http://schema.org/contentUrl")
     */
    public $contentUrl;
}
