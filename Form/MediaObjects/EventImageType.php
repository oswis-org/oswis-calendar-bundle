<?php
/**
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Form\MediaObjects;

use Symfony\Component\Form\AbstractType;
use Zakjakub\OswisCalendarBundle\Entity\MediaObject\EventImage;

final class EventImageType extends AbstractType
{
    public static function getImageClassName(): string
    {
        return EventImage::class;
    }
}
