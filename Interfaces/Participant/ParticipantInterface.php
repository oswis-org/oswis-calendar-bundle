<?php

namespace OswisOrg\OswisCalendarBundle\Interfaces\Participant;

use OswisOrg\OswisCoreBundle\Interfaces\Common\ActivatedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\ManagerConfirmationInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\PriorityInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\UserConfirmationInterface;

interface ParticipantInterface extends BasicInterface, PriorityInterface, ActivatedInterface, DeletedInterface, UserConfirmationInterface, ManagerConfirmationInterface
{
}
