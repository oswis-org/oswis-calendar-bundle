<?php

namespace OswisOrg\OswisCalendarBundle\Controller\Event;

use OswisOrg\OswisCalendarBundle\Entity\Event\EventFile;
use OswisOrg\OswisCoreBundle\Controller\AbstractClass\AbstractFileAction;

final class EventFileAction extends AbstractFileAction
{
    public static function getFileClassName(): string
    {
        return EventFile::class;
    }

    public static function getFileNewInstance(): EventFile
    {
        return new EventFile();
    }
}
