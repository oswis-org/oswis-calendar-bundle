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
        assert($rootNode instanceof ArrayNodeDefinition);
        $rootNode->info('Default configuration for calendar module for OSWIS (One Simple Web IS).');
        $this->addDefaultEvent($rootNode);
        $this->addDefaultEventFallback($rootNode);
        $rootNode->end();

        return $treeBuilder;
    }

    private function addDefaultEvent(ArrayNodeDefinition $rootNode): void
    {
        $rootNode->children()
            ->scalarNode('default_event')
            ->defaultNull()
            ->end()
            ->end();
    }

    private function addDefaultEventFallback(ArrayNodeDefinition $rootNode): void
    {
        $rootNode->fixXmlConfig('default_event_fallback')
            ->children()
            ->arrayNode('default_event_fallbacks')
            ->defaultValue([])
            ->scalarPrototype()
            ->end()
            ->end()
            ->end();
    }

}
