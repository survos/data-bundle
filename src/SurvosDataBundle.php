<?php

declare(strict_types=1);

namespace Survos\DataBundle;

use Survos\DataBundle\EventListener\RelationExtractorListener;
use Survos\DataBundle\EventListener\VocabTermExtractorListener;
use Survos\DataContracts\Path\DataPaths;
use Survos\DataContracts\Vocabulary\MuseumVocab;
use Survos\Kit\AbstractSurvosBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosDataBundle extends AbstractSurvosBundle
{
    /**
     * Icon aliases for the vocabularies defined in data-contracts, keyed by the vocab code itself (the
     * MuseumVocab constant, which now single-sources its entity codes from Core), so folio renders
     * `ux_icon(code)` for a Term/Relation group with no extra mapping. Declaring them as aliases
     * (rather than hardcoding tabler:* in templates) also lets `ux:icons:lock` cache them.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::prependExtension($container, $builder);

        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', [
                'aliases' => MuseumVocab::ICONS,
            ]);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // The two extractor listeners write under APP_DATA_DIR and need a DataPaths service.
        // DataPaths is a data-contracts class (always present), but the SERVICE comes from
        // whoever wires it: dataset-bundle, folio-bundle, or the app's services.yaml. That is
        // only known once every extension has loaded, so decide here rather than in
        // loadExtension(), and drop the listeners when nobody wired one.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->has(DataPaths::class)) {
                    return;
                }
                $container->removeDefinition(VocabTermExtractorListener::class);
                $container->removeDefinition(RelationExtractorListener::class);
            }
        }, priority: 100);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $ns = 'Survos\\DataBundle\\';
        $srcDir = $this->getPath().'/src/';

        $services = $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure();

        foreach (['EventListener', 'Service'] as $dir) {
            if (!is_dir($srcDir.$dir)) {
                continue;
            }

            $services->load($ns.$dir.'\\', $srcDir.$dir.'/');
        }

        if (is_dir($srcDir.'Repository')) {
            $services->load($ns.'Repository\\', $srcDir.'Repository/')
                ->tag('doctrine.repository_service');
        }
    }
}
