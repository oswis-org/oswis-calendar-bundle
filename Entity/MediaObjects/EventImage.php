<?php

namespace Zakjakub\OswisCalendarBundle\Entity\MediaObject;

use ApiPlatform\Core\Annotation\ApiResource;
use Symfony\Component\HttpFoundation\File\File;
use Zakjakub\OswisCalendarBundle\Controller\MediaObjects\CreateEventImageAction;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractImage;

/**
 * @Doctrine\ORM\Mapping\Entity()
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
class EventImage extends AbstractImage
{
    /**
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Vich\UploaderBundle\Mapping\Annotation\UploadableField(
     *     mapping="calendar_event_image",
     *     fileNameProperty="contentUrl",
     *     dimensions={"contentDimensionsWidth", "contentDimensionsHeight"},
     *     mimeType="contentDimensionsMimeType"
     * )
     */
    public ?File $file = null;
}
