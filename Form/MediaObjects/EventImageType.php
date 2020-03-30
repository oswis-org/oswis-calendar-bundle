<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace Zakjakub\OswisCalendarBundle\Form\MediaObjects;

use Zakjakub\OswisCalendarBundle\Entity\MediaObjects\EventImage;
use Zakjakub\OswisCoreBundle\Form\AbstractClass\AbstractImageType;

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
