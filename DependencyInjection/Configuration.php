<?php

namespace OswisOrg\OswisCalendarBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * @return TreeBuilder
     */
    final public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('oswis_org_oswis_calendar', 'array');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode->info('Default configuration for calendar module for OSWIS (One Simple Web IS).');
        $this->addDefaultEvent($rootNode);
        $rootNode->end();

        return $treeBuilder;
    }

    private function addDefaultEvent(ArrayNodeDefinition $rootNode): void
    {
        $rootNode->children()
            ->scalarNode('default_event')
            ->end()
            ->scalarNode('default_event_fallback')
            ->end()
            ->end();
    }

}
