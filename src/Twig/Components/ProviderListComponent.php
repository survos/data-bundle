<?php
declare(strict_types=1);

namespace Survos\DataBundle\Twig\Components;

use Survos\DataBundle\Repository\ProviderRepository;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\Mount;
use Symfony\Component\Routing\RouterInterface;

#[AsTwigComponent('ProviderList')]
final class ProviderListComponent
{
    #[Mount]
    public array $providers = [];

    public function __construct(
        private readonly ProviderRepository $providerRepository,
        private readonly MeiliService $meiliService,
        private readonly IndexInfoRepository $indexInfoRepository,
        private readonly RouterInterface $router,
    ) {
    }

    public function mount(): void
    {
        $providers = $this->providerRepository->findAllOrdered();

        $rows = [];
        foreach ($providers as $provider) {
            $code = $provider->getCode();

            // Get Meilisearch indexes for this provider by matching indexName prefix
            // e.g., md_dc, md_dccoll, md_smithobj all belong to provider "dc" or "smith"
            $allIndexes = $this->indexInfoRepository->findAll();
            $indexInfos = array_filter($allIndexes, fn($idx) => 
                str_starts_with($idx->indexName, 'md_' . $code) ||
                $idx->indexName === 'md_' . $code
            );

            // API Platform dataset collection URL
            $apiUrl = $this->router->generate('_api_/dataset_infos_get_collection', ['aggregator' => $code]);

            $rows[] = [
                'code' => $code,
                'label' => $provider->getLabel(),
                'description' => $provider->getDescription(),
                'homepage' => $provider->getHomepage(),
                'datasetCount' => $provider->getDatasetCount() ?? 0,
                'indexInfos' => array_values($indexInfos),
                'apiUrl' => $apiUrl,
            ];
        }

        $this->providers = $rows;
    }
}
