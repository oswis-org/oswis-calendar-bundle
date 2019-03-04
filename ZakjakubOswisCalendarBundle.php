<?php

namespace Zakjakub\OswisCalendarBundle;

use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Zakjakub\OswisCalendarBundle\DependencyInjection\ZakjakubOswisCalendarExtension;

class ZakjakubOswisCalendarBundle extends Bundle
{
    final public function getContainerExtension(): Extension
    {
        return new ZakjakubOswisCalendarExtension();
    }
}
