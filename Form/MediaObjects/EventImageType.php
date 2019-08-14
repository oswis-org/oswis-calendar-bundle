<?php

namespace Zakjakub\OswisCalendarBundle\Form\MediaObjects;

use Symfony\Component\Form\AbstractType;
use Zakjakub\OswisCalendarBundle\Entity\MediaObject\EventImage;

final class EventImageType extends AbstractType
{

    /**
     * @return string
     */
    public static function getImageClassName(): string
    {
        return EventImage::class;
    }
}
