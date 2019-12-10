<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\Event;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_flag_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventFlagConnection
{
    use BasicEntityTrait;
}
