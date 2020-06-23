<?php

namespace OswisOrg\OswisCalendarBundle\Controller\MediaObject;

use OswisOrg\OswisCalendarBundle\Entity\MediaObject\EventImage;
use OswisOrg\OswisCoreBundle\Controller\AbstractClass\AbstractImageAction;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractImage;

final class EventImageAction extends AbstractImageAction
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
