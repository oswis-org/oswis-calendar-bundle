<?php

namespace OswisOrg\OswisCalendarBundle;

use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use OswisOrg\OswisCalendarBundle\DependencyInjection\OswisOrgOswisCalendarExtension;

class OswisOrgOswisCalendarBundle extends Bundle
{
    final public function getContainerExtension(): Extension
    {
        return new OswisOrgOswisCalendarExtension();
    }
}
