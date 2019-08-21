<?php

namespace Zakjakub\OswisCalendarBundle\DependencyInjection;

use RuntimeException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{

    /**
     * @return TreeBuilder
     * @throws RuntimeException
     */
    final public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('zakjakub_oswis_calendar');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode->info('Default configuration for address book module for OSWIS (One Simple Web IS).')->children()->end()->end();

        return $treeBuilder;
    }
}
