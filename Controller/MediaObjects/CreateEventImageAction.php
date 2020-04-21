<?php

namespace OswisOrg\OswisCalendarBundle\Controller\MediaObjects;

use OswisOrg\OswisCalendarBundle\Entity\MediaObjects\EventImage;
use OswisOrg\OswisCoreBundle\Controller\AbstractClass\AbstractImageAction;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractImage;

final class CreateEventImageAction extends AbstractImageAction
{

    public static function getFileClassName(): string
    {
        return EventImage::class;
    }

    public static function getFileNewInstance(): AbstractImage
    {
        return new EventImage();
    }
}
