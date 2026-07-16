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
     * @param array<array> $configs
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
     * @param array $config
     *
     * @throws ServiceNotFoundException
     */
    private function oswisCalendarSettingsProvider(ContainerBuilder $container, array $config): void
    {
        $definition = $container->getDefinition('oswis_org_oswis_calendar.oswis_calendar_settings_provider');
        $definition->setArgument(0, $config['default_event']);
        $definition->setArgument(1, $config['default_event_fallbacks']);
        $definition->setArgument(2, $config['external_redirects']);
    }

    final public function prepend(ContainerBuilder $container): void
    {
        $this->prependApiPlatform($container);
    }

    /**
     * Doménové výjimky registrace/kapacity → smysluplné HTTP stavy na /api. Bez mapování je
     * „přesun/registrace do plné akce" přes API 500 (request.CRITICAL v prod logu — 3.7. i 14.7.2026),
     * přestože jde o očekávaný stav řešitelný klientem. 409 = konflikt s aktuálním stavem zdroje
     * (plná kapacita), 400 = nevalidní kombinace příznaků. Webové (ne-API) cesty mají vlastní
     * catch + friendly odpověď, tohle se jich netýká.
     */
    private function prependApiPlatform(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('api_platform')) {
            return;
        }
        $container->prependExtensionConfig('api_platform', [
            'exception_to_status' => [
                \OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException::class => 409,
                \OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException::class => 409,
                \OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException::class => 400,
            ],
        ]);
    }
}
