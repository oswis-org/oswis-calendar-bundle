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
        $this->addExternalRedirects($rootNode);
        $rootNode->end();

        return $treeBuilder;
    }

    /** @noinspection NullPointerExceptionInspection */
    private function addDefaultEvent(ArrayNodeDefinition $rootNode): void
    {
        $rootNode->children()->scalarNode('default_event')->defaultNull()->end()->end();
    }

    /** @noinspection NullPointerExceptionInspection */
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


    private function addExternalRedirects(ArrayNodeDefinition $rootNode): void
    {
        $rootNode->children()
            ->arrayNode('external_redirects')
            ->info('Redirects after actions such as participant verification.')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('participant_activated')
            ->info("Participant successfully activated by token from e-mail.")
            ->defaultValue(null)
            ->example('https://www.example.com/participant_activated?title={title}&message={message}')
            ->end()
            ->scalarNode('participant_invalid_token')
            ->info('Token is not valid for given participant.')
            ->defaultValue(null)
            ->example('https://www.example.com/participant_invalid_token?title={title}&message={message}')
            ->end()
            ->scalarNode('participant_activation_error')
            ->info('Participant not activated for some reason.')
            ->defaultValue(null)
            ->example('https://www.example.com/participant_activation_error?title={title}&message={message}')
            ->end()
            ->scalarNode('participant_verification_resent')
            ->info('Verification message was sent once again.')
            ->defaultValue(null)
            ->example('https://www.example.com/participant_verification_resent?title={title}&message={message}')
            ->end()
            ->end()
            ->end();
    }


}
