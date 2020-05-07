<?php

namespace OswisOrg\OswisCalendarBundle\Entity\AbstractClass;

use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableEntityInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableBasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ValueTrait;

abstract class AbstractEventFlag implements NameableEntityInterface
{
    use NameableBasicTrait;
    use ValueTrait;
    use EntityPublicTrait;
    use ColorTrait;
}
