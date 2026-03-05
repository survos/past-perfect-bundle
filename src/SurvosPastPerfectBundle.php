<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle;

use Survos\PastPerfectBundle\Command\DiscoverRegistryCommand;
use Survos\PastPerfectBundle\Command\HarvestDetailCommand;
use Survos\PastPerfectBundle\Command\HarvestListingCommand;
use Survos\PastPerfectBundle\Command\ProbeRegistryCommand;
use Survos\PastPerfectBundle\MessageHandler\ProbeItemHandler;
use Survos\PastPerfectBundle\MessageHandler\ProbeRegistrySiteHandler;
use Survos\PastPerfectBundle\Service\DetailParserService;
use Survos\PastPerfectBundle\Service\PastPerfectClient;
use Survos\PastPerfectBundle\Service\SiteProbeService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class SurvosPastPerfectBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->floatNode('throttle')->defaultValue(1.0)->end()
                ->scalarNode('cache_dir')->defaultValue('var/pastperfect')->end()
                ->scalarNode('user_agent')->defaultValue('SurvosPastPerfectBundle Harvester')->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // --- Core services ---

        $services->set(PastPerfectClient::class)
            ->args([
                service('http_client'),
                $config['throttle'],
                $config['user_agent'],
                $config['cache_dir'],
            ]);

        $services->set(DetailParserService::class);

        $services->set(SiteProbeService::class)
            ->args([
                service('http_client'),
                $config['user_agent'],
            ]);

        // DiscoverRegistryCommand uses CdxDiscoveryService from site-discovery-bundle.
        // That bundle registers it as an autowirable service.

        // --- Harvest commands ---

        $services->set(HarvestListingCommand::class)
            ->autowire()
            ->tag('console.command');

        $services->set(HarvestDetailCommand::class)
            ->autowire()
            ->tag('console.command');

        // --- Registry commands ---

        $services->set(DiscoverRegistryCommand::class)
            ->autowire()
            ->tag('console.command');

        $services->set(ProbeRegistryCommand::class)
            ->args([
                service('messenger.default_bus'),
                service('Survos\\JsonlBundle\\Service\\JsonlStateRepository'),
            ])
            ->tag('console.command');

        // --- Message handlers ---

        $services->set(ProbeRegistrySiteHandler::class)
            ->autowire()
            ->tag('messenger.message_handler');

        $services->set(ProbeItemHandler::class)
            ->autowire()
            ->tag('messenger.message_handler');
    }
}
