<?php

namespace OswisOrg\OswisCalendarBundle\Entity\AbstractClass;

use OswisOrg\OswisCoreBundle\Interfaces\BasicEntityInterface;
use OswisOrg\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Entity\ValueTrait;

abstract class AbstractEventFlag implements BasicEntityInterface
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use ValueTrait;
    use EntityPublicTrait;
    use ColorTrait;
}
