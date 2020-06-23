<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\MediaObject;

use OswisOrg\OswisCalendarBundle\Entity\MediaObject\EventFile;
use OswisOrg\OswisCoreBundle\Form\AbstractClass\AbstractFileType;

class EventFileType extends AbstractFileType
{
    public static function getFileClassName(): string
    {
        return EventFile::class;
    }

    public function getBlockPrefix(): string
    {
        return 'oswis_calendar_event_file';
    }
}
