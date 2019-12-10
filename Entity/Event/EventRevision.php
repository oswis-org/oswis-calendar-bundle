<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

/**
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="Zakjakub\OswisCalendarBundle\Repository\EventRevisionRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_revision")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventRevision
{
    use BasicEntityTrait;
}
