<?php

namespace OswisOrg\OswisCalendarBundle\Entity\AbstractClass;

use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ValueTrait;

abstract class AbstractEventFlag implements NameableInterface
{
    use NameableTrait;
    use ValueTrait;
    use ColorTrait;

    public function __construct(?Nameable $nameable = null)
    {
        $this->setFieldsFromNameable($nameable);
    }

    public static function getAllowedTypesDefault(): array
    {
        return [];
    }

    public static function getAllowedTypesCustom(): array
    {
        return [];
    }
}
