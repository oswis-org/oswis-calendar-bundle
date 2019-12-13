<?php

namespace Zakjakub\OswisCalendarBundle\Controller\MediaObjects;

use Zakjakub\OswisCalendarBundle\Entity\MediaObject\EventImage;
use Zakjakub\OswisCoreBundle\Controller\AbstractClass\AbstractImageAction;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractImage;

final class CreateEventImageAction extends AbstractImageAction
{

    public static function getImageClassName(): string
    {
        return EventImage::class;
    }

    public static function getImageNewInstance(): AbstractImage
    {
        return new EventImage();
    }
}
