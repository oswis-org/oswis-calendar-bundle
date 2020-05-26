<?php

namespace OswisOrg\OswisCalendarBundle\Entity\AbstractClass;

use DateTime;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

abstract class AbstractEventFlagConnection implements BasicInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DateRangeTrait;

    public function __construct(?string $textValue = null, ?DateTime $startDateTime = null, ?DateTime $endDateTime = null)
    {
        $this->setTextValue($textValue);
        $this->setStartDateTime($startDateTime);
        $this->setEndDateTime($endDateTime);
    }
}
