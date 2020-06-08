<?php

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractWebContent;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_content")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 */
class EventContent extends AbstractWebContent
{
}
