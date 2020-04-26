<?php

namespace OswisOrg\OswisCalendarBundle\DependencyInjection;

use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class OswisOrgOswisCalendarExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Loads a specific configuration.
     *
     * @param array<array>     $configs
     * @param ContainerBuilder $container
     *
     * @throws Exception
     */
    final public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
        $configuration = $this->getConfiguration($configs, $container);
        if ($configuration) {
            $config = $this->processConfiguration($configuration, $configs);
            $this->oswisCalendarSettingsProvider($container, $config);
        }
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     *
     * @throws ServiceNotFoundException
     */
    private function oswisCalendarSettingsProvider(ContainerBuilder $container, array $config): void
    {
        $definition = $container->getDefinition('oswis_org_oswis_calendar.oswis_calendar_settings_provider');
        $definition->setArgument(0, $config['default_event']);
        $definition->setArgument(1, $config['default_event_fallback']);
    }

    final public function prepend(ContainerBuilder $container): void
    {
    }
}
