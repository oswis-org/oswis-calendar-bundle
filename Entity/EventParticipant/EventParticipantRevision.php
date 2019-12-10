<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_revision")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantRevision
{
    use BasicEntityTrait;
}
