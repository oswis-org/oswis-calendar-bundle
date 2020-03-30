<?php

namespace Zakjakub\OswisCalendarBundle\Controller\MediaObjects;

use Zakjakub\OswisCalendarBundle\Entity\MediaObjects\EventImage;
use Zakjakub\OswisCoreBundle\Controller\AbstractClass\AbstractImageAction;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractImage;

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
