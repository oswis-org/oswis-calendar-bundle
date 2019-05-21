<?php

namespace ZakJakub\OswisCalendarBundle\Entity\AbstractClass;

use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\EntityPublicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ValueTrait;

abstract class AbstractEventFlag
{

    use BasicEntityTrait;
    use NameableBasicTrait;
    use ValueTrait;
    use EntityPublicTrait;

}