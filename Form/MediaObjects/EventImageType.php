<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\MediaObjects;

use OswisOrg\OswisCalendarBundle\Entity\MediaObjects\EventImage;
use OswisOrg\OswisCoreBundle\Form\AbstractClass\AbstractImageType;

class EventImageType extends AbstractImageType
{
    public static function getFileClassName(): string
    {
        return EventImage::class;
    }

    public function getBlockPrefix(): string
    {
        return 'oswis_calendar_event_image';
    }
}
