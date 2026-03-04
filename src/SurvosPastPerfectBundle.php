<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle;

use Survos\PastPerfectBundle\Command\HarvestListingCommand;
use Survos\PastPerfectBundle\Service\PastPerfectClient;
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

        $services->set(PastPerfectClient::class)
            ->args([
                service('http_client'),
                $config['throttle'],
                $config['user_agent'],
            ]);

        $services->set(HarvestListingCommand::class)
            ->autowire()
            ->tag('console.command');
    }
}
