<?php

declare(strict_types=1);

namespace Survos\DataBundle;

use Survos\DataContracts\Vocabulary\MuseumVocab;
use Survos\Kit\AbstractSurvosBundle;
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
                'aliases' => [
                    MuseumVocab::PERSON       => 'tabler:user',
                    MuseumVocab::COLLECTION   => 'tabler:folder',
                    MuseumVocab::ORGANISATION => 'tabler:building',
                    MuseumVocab::SUBJECT      => 'tabler:tag',
                    MuseumVocab::PLACE        => 'tabler:map-pin',
                    MuseumVocab::GENRE        => 'tabler:category',
                    MuseumVocab::CULTURE      => 'tabler:world',
                    MuseumVocab::MEDIUM       => 'tabler:palette',
                    MuseumVocab::TECHNIQUE    => 'tabler:brush',
                    MuseumVocab::MATERIAL     => 'tabler:cube',
                    MuseumVocab::PERIOD       => 'tabler:calendar',
                    MuseumVocab::EPOCH        => 'tabler:hourglass',
                    MuseumVocab::DEPARTMENT   => 'tabler:sitemap',
                ],
            ]);
        }
    }

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
