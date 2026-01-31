<?php
declare(strict_types=1);

namespace Survos\DataBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataBundle\Repository\DatasetInfoRepository;

#[ORM\Entity(repositoryClass: DatasetInfoRepository::class)]
final class DatasetInfo
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 128)]
    public readonly string $datasetKey;

    #[ORM\Column(nullable: true)]
    public ?string $label = null;

    #[ORM\Column(nullable: true)]
    public ?string $sourceType = null;

    #[ORM\Column(nullable: true)]
    public ?string $jsonlPath = null;

    #[ORM\Column(nullable: true)]
    public ?string $profilePath = null;

    /** @var string[] */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $rootPaths = [];

    #[ORM\Column(nullable: true)]
    public ?string $templateKey = null;

    #[ORM\Column(nullable: true)]
    public ?string $templatePath = null;

    #[ORM\Column(nullable: true)]
    public ?string $metaPath = null;

    public function __construct(string $datasetKey)
    {
        $this->datasetKey = $datasetKey;
    }
}
