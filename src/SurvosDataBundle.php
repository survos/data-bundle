<?php
declare(strict_types=1);

namespace Survos\DataBundle;

use Survos\DataBundle\Command\DataDiagCommand;
use Survos\DataBundle\Command\DataHeadCommand;
use Survos\DataBundle\Command\DataPathCommand;
use Survos\DataBundle\Command\DataBrowseCommand;
use Survos\DataBundle\Context\DatasetContext;
use Survos\DataBundle\Context\DatasetResolver;
use Survos\DataBundle\DBAL\MultiDbConnectionWrapper;
use Survos\DataBundle\EventListener\DatasetContextConsoleListener;
use Survos\DataBundle\Meta\DatasetMetadataConfiguration;
use Survos\DataBundle\Meta\DatasetMetadataEnsurer;
use Survos\DataBundle\Meta\DatasetMetadataLoader;
use Survos\DataBundle\Service\DataPaths;
use Survos\DataBundle\Service\SurvosDatasetPathsFactory;
use Survos\DataBundle\Repository\DatasetInfoRepository;
use Survos\ImportBundle\Contract\DatasetContextInterface;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosDataBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('data_dir')->defaultValue('%env(APP_DATA_DIR)%')->end()
                ->scalarNode('dataset_root')->defaultValue('data')->end()
                ->scalarNode('pixie_root')->defaultValue('pixie')->end()
                ->scalarNode('runs_root')->defaultValue('runs')->end()
                ->scalarNode('cache_root')->defaultValue('cache')->end()
                ->scalarNode('zips_root')->defaultValue('%env(ZIPS_DIR)%')->end()
                ->scalarNode('default_object_filename')->defaultValue('obj.jsonl')->end()
                ->scalarNode('tenant_database_prefix')->defaultValue('')->end()
                ->arrayNode('tenants')
                    ->useAttributeAsKey('code')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('database')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // Core service
        $services->set(DataPaths::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->args([
                '$dataDir' => $config['data_dir'],
                '$datasetRoot' => $config['dataset_root'],
                '$pixieRoot' => $config['pixie_root'],
                '$runsRoot' => $config['runs_root'],
                '$cacheRoot' => $config['cache_root'],
                '$zipsRoot' => $config['zips_root'],
                '$defaultObjectFilename' => $config['default_object_filename'],
            ]);

        // Dataset metadata
        foreach ([DatasetMetadataConfiguration::class, DatasetMetadataLoader::class, DatasetMetadataEnsurer::class] as $class) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        // Dataset path resolver (overrides import-bundle default)
        if (interface_exists(DatasetPathsFactoryInterface::class)) {
            $services->set(SurvosDatasetPathsFactory::class)
                ->autowire()
                ->autoconfigure()
                ->public();

            $services->alias(
                DatasetPathsFactoryInterface::class,
                SurvosDatasetPathsFactory::class
            )->public();
        }

        // Dataset execution context (for console commands)
        foreach ([DatasetContext::class, DatasetResolver::class, DatasetContextConsoleListener::class] as $class) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        // Alias for cross-bundle reuse
        if (interface_exists(DatasetContextInterface::class)) {
            $services->alias(DatasetContextInterface::class, DatasetContext::class)->public();
        }

        //

//        $services->set(MultiDbConnectionWrapper::class)
//            ->autowire()
//            ->autoconfigure()
//            ->public();

        // Console command(s)
        $services->set(DataDiagCommand::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->set(DataPathCommand::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->set(DataHeadCommand::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->set(DataBrowseCommand::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->set(DatasetInfoRepository::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.repository_service');

        $services->set(Tenant\TenantRegistry::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->args([
                '$databasePrefix' => $config['tenant_database_prefix'],
                '$tenants' => $config['tenants'],
            ]);
    }
}
