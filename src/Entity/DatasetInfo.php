<?php
declare(strict_types=1);

namespace Survos\DataBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataBundle\Repository\DatasetInfoRepository;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Registry of all known datasets — populated once by scanning 00_meta/dataset.yaml
 * files, then used for all subsequent lookups without touching the filesystem.
 *
 * Replaces:
 *   - Directory scanning in PixieService::getConfigFiles()
 *   - YAML-based pixie registry
 *   - Repeated Yaml::parseFile() calls at runtime
 *
 * Populate with: bin/console data:scan-datasets
 * Then all pixie:migrate, pixie:ingest, meili:push operations use this registry.
 */
#[ORM\Entity(repositoryClass: DatasetInfoRepository::class)]
#[ORM\Index(columns: ['aggregator'])]
#[ORM\Index(columns: ['locale'])]
#[ORM\Index(columns: ['status'])]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/dataset_infos', normalizationContext: ['groups' => ['dataset:read']]),
        new Get(uriTemplate: '/dataset_infos/{datasetKey}', normalizationContext: ['groups' => ['dataset:read']]),
    ],
    normalizationContext: ['groups' => ['dataset:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['datasetKey' => 'partial', 'label' => 'partial', 'aggregator' => 'exact', 'status' => 'exact', 'country' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['datasetKey', 'label', 'aggregator', 'status', 'objCount', 'normalizedCount', 'lastScanned'])]
final class DatasetInfo
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 128)]
    #[Groups(['dataset:read'])]
    public readonly string $datasetKey;  // e.g. "fortepan/hu", "dc/0v83gg01j"

    // ── From 00_meta/dataset.yaml ─────────────────────────────────────────────

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['dataset:read'])]
    public ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?string $aggregator = null;    // dc | pp | fortepan | mds | mus | etc.

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?string $locale = null;        // default locale: en | de | hu | etc.

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?string $country = null;       // ISO2: US | HU | GB | etc.

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?string $contactUrl = null;    // collection homepage

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?string $rightsUri = null;

    #[ORM\Column]
    #[Groups(['dataset:read'])]
    public int $objCount = 0;             // from extras.obj_count or profile recordCount

    // ── Paths (resolved at scan time, no filesystem access needed later) ──────

    #[ORM\Column(nullable: true)]
    public ?string $metaPath = null;      // 00_meta/dataset.yaml

    #[ORM\Column(nullable: true)]
    public ?string $rawPath = null;       // 05_raw/obj.jsonl

    #[ORM\Column(nullable: true)]
    public ?string $normalizedPath = null; // 20_normalize/obj.jsonl

    #[ORM\Column(nullable: true)]
    public ?string $profilePath = null;   // 21_profile/obj.profile.json

    #[ORM\Column(nullable: true)]
    public ?string $pixieDbPath = null;   // pixie/<code>.db SQLite file

    // ── Pipeline status ───────────────────────────────────────────────────────

    /** One of: discovered | raw | normalized | profiled | pixie | indexed */
    #[ORM\Column(length: 32)]
    #[Groups(['dataset:read'])]
    public string $status = 'discovered';

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?int $normalizedCount = null;   // from profile recordCount

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?int $pixieRowCount = null;     // rows in pixie db after ingest

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?int $meiliDocCount = null;     // docs in Meilisearch index

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?\DateTimeImmutable $lastScanned = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?\DateTimeImmutable $lastNormalized = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    public ?\DateTimeImmutable $lastIndexed = null;

    // ── Compiled schema (from profile + field_map) ────────────────────────────

    /**
     * Core names available for this dataset (e.g. ["obj"] or ["obj", "cat", "type"]).
     * Populated by data:scan-datasets from profile files.
     * Used by pixie:migrate to create Core rows without filesystem access.
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    public array $cores = [];

    /**
     * Field names per core from the profile.
     * e.g. {"obj": ["id","title","year","donor","tags","country","city","latitude"]}
     *
     * @var array<string, string[]>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $fields = [];

    /**
     * Compact subset of obj.profile.json for fast registry decisions.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $profileSummary = null;

    /**
     * Meilisearch settings derived from heuristic + field_map.
     * Cached here so pixie:index and meili:push don't need to re-derive.
     * e.g. {"filterable": ["year","country","city"], "searchable": ["title","tags"]}
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $meiliSettings = [];

    /**
     * Full 00_meta/dataset.yaml content cached as JSON.
     * Avoids re-reading the file for every registry lookup.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $meta = [];

    public function __construct(string $datasetKey)
    {
        $this->datasetKey = $datasetKey;
    }

    // ── Derived helpers ───────────────────────────────────────────────────────

    public function provider(): string
    {
        return explode('/', $this->datasetKey, 2)[0];
    }

    #[Groups(['dataset:read'])]
    public function getProvider(): string
    {
        return $this->provider();
    }

    public function code(): string
    {
        return explode('/', $this->datasetKey, 2)[1] ?? $this->datasetKey;
    }

    #[Groups(['dataset:read'])]
    public function getCode(): string
    {
        return $this->code();
    }

    /** The pixie code used for the SQLite filename: "fortepan/hu" → "fortepan_hu" */
    public function pixieCode(): string
    {
        return str_replace('/', '_', $this->datasetKey);
    }

    public function hasRaw(): bool        { return $this->rawPath !== null && is_file($this->rawPath); }
    public function hasNormalized(): bool { return $this->normalizedPath !== null && is_file($this->normalizedPath); }
    public function hasProfile(): bool    { return $this->profilePath !== null && is_file($this->profilePath); }
    public function hasPixieDb(): bool    { return $this->pixieDbPath !== null && is_file($this->pixieDbPath); }

    public function isReadyForPixie(): bool
    {
        return $this->hasNormalized() && $this->hasProfile();
    }

    public function isReadyForMeili(): bool
    {
        return $this->hasNormalized() && $this->normalizedCount >= 1;
    }
}
