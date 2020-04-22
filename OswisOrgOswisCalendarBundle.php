<?php

namespace OswisOrg\OswisCalendarBundle;

use OswisOrg\OswisCalendarBundle\DependencyInjection\OswisOrgOswisCalendarExtension;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class OswisOrgOswisCalendarBundle extends Bundle
{
    final public function getContainerExtension(): Extension
    {
        return new OswisOrgOswisCalendarExtension();
    }
}
