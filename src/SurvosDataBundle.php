<?php
declare(strict_types=1);

namespace Survos\DataBundle;

use Survos\DataBundle\Command\DataBrowseCommand;
use Survos\DataBundle\Command\DataDiagCommand;
use Survos\DataBundle\Command\DataHeadCommand;
use Survos\DataBundle\Command\DataPathCommand;
use Survos\DataBundle\Command\ScanDatasetsCommand;
use Survos\DataBundle\Context\DatasetContext;
use Survos\DataBundle\Context\DatasetResolver;
use Survos\DataBundle\Controller\ProviderController;
use Survos\DataBundle\Entity\DatasetInfo;
use Survos\DataBundle\EventListener\DatasetContextConsoleListener;
use Survos\DataBundle\Meta\DatasetMetadataConfiguration;
use Survos\DataBundle\Meta\DatasetMetadataEnsurer;
use Survos\DataBundle\Meta\DatasetMetadataLoader;
use Survos\DataBundle\Repository\CandidateRepository;
use Survos\DataBundle\Repository\DatasetInfoRepository;
use Survos\DataBundle\Repository\ProviderRepository;
use Survos\DataBundle\Twig\Components\ProviderListComponent;
use Survos\DataBundle\Service\DataPaths;
use Survos\DataBundle\Service\ProviderSnapshotCodec;
use Survos\DataBundle\Service\SurvosDatasetPathsFactory;
use Survos\ImportBundle\Contract\DatasetContextInterface;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class SurvosDataBundle extends AbstractBundle
{

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('data_dir')->defaultValue('%env(APP_DATA_DIR)%')->end()
                ->scalarNode('dataset_root')->defaultValue('work')->end()
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

        $services->set(ProviderSnapshotCodec::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        foreach ([DatasetMetadataConfiguration::class, DatasetMetadataLoader::class, DatasetMetadataEnsurer::class] as $class) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        if (interface_exists(DatasetPathsFactoryInterface::class)) {
            $services->set(SurvosDatasetPathsFactory::class)
                ->autowire()
                ->autoconfigure()
                ->public();

            $services->alias(DatasetPathsFactoryInterface::class, SurvosDatasetPathsFactory::class)
                ->public();
        }

        foreach ([DatasetContext::class, DatasetResolver::class, DatasetContextConsoleListener::class] as $class) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        if (interface_exists(DatasetContextInterface::class)) {
            $services->alias(DatasetContextInterface::class, DatasetContext::class)->public();
        }

        foreach ([DataDiagCommand::class, DataPathCommand::class, DataHeadCommand::class, DataBrowseCommand::class, ScanDatasetsCommand::class] as $class) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        $services->set(DatasetInfoRepository::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.repository_service');

        $services->set(CandidateRepository::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.repository_service');

        $services->set(ProviderRepository::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.repository_service');

        $services->set(ProviderListComponent::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->set(ProviderController::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->set('survos_data.api_resource.dataset_info', DatasetInfo::class)
            ->abstract()
            ->tag('api_platform.resource');

        $services->set(Tenant\TenantRegistry::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->args([
                '$databasePrefix' => $config['tenant_database_prefix'],
                '$tenants' => $config['tenants'],
            ]);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $entityDir = dirname(__DIR__) . '/src/Entity';
        $templateDir = dirname(__DIR__) . '/templates';

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'SurvosDataBundle' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => $entityDir,
                            'prefix' => 'Survos\DataBundle\Entity',
                            'alias' => 'SurvosDataBundle',
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('api_platform')) {
            $builder->prependExtensionConfig('api_platform', [
                'mapping' => [
                    'paths' => [$entityDir],
                ],
            ]);
        }

        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', [
                'paths' => [
                    $templateDir => 'SurvosDataBundle',
                ],
            ]);
        }

        if ($builder->hasExtension('twig_component')) {
            $builder->prependExtensionConfig('twig_component', [
                'defaults' => [
                    'Survos\\DataBundle\\Twig\\Components\\' => [
                        'template_directory' => '@SurvosDataBundle/components/',
                    ],
                ],
            ]);
        }
    }

    public function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(dirname(__DIR__) . '/src/Controller/', 'attribute');

        if (!class_exists('ApiPlatform\\Action\\PlaceholderAction')) {
            return;
        }

        $defaults = [
            '_controller' => 'api_platform.action.placeholder',
            '_stateless' => true,
            '_api_resource_class' => DatasetInfo::class,
        ];

        $routes->add('_api_/dataset_infos_get_collection', '/api/dataset_infos')
            ->methods(['GET'])
            ->defaults($defaults + [
                '_api_operation_name' => '_api_/dataset_infos_get_collection',
                '_format' => null,
            ]);

        $routes->add('_api_/dataset_infos/{datasetKey}_get', '/api/dataset_infos/{datasetKey}')
            ->methods(['GET'])
            ->defaults($defaults + [
                '_api_operation_name' => '_api_/dataset_infos/{datasetKey}_get',
                '_format' => null,
            ]);
    }

}
