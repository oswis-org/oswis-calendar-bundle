<?php

namespace Zakjakub\OswisCalendarBundle\Entity\AbstractClass;

use Zakjakub\OswisCoreBundle\Interfaces\BasicEntityInterface;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ColorTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\EntityPublicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\NameableBasicTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\ValueTrait;

abstract class AbstractEventFlag implements BasicEntityInterface
{
    use BasicEntityTrait;
    use NameableBasicTrait;
    use ValueTrait;
    use EntityPublicTrait;
    use ColorTrait;
}
