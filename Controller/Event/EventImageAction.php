<?php

namespace OswisOrg\OswisCalendarBundle\Controller\Event;

use OswisOrg\OswisCalendarBundle\Entity\Event\EventImage;
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
