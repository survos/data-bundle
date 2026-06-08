<?php

declare(strict_types=1);

namespace Survos\DataBundle;

use Survos\Kit\AbstractSurvosBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class SurvosDataBundle extends AbstractSurvosBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $ns     = 'Survos\\DataBundle\\';
        $srcDir = $this->getPath() . '/src/';

        $services = $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure();

        foreach (['EventListener', 'Service'] as $dir) {
            if (!is_dir($srcDir . $dir)) {
                continue;
            }

            $definition = $services->load($ns . $dir . '\\', $srcDir . $dir . '/');
            if ('EventListener' === $dir && !class_exists('Survos\\DatasetBundle\\Service\\DataPaths')) {
                $definition->exclude($srcDir . 'EventListener/VocabTermExtractorListener.php');
            }
        }

        if (is_dir($srcDir . 'Repository')) {
            $services->load($ns . 'Repository\\', $srcDir . 'Repository/')
                ->tag('doctrine.repository_service');
        }
    }
}
