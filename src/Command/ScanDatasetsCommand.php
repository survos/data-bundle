<?php
declare(strict_types=1);

namespace Survos\DataBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataBundle\Entity\DatasetInfo;
use Survos\DataBundle\Repository\DatasetInfoRepository;
use Survos\DataBundle\Service\DataPaths;
use Survos\DataBundle\Service\DatasetPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Scans APP_DATA_DIR for 00_meta/dataset.yaml files and populates DatasetInfo.
 *
 * Run once after fetching/normalizing data, then all registry lookups
 * use the DB — no more directory scanning at runtime.
 *
 * Usage:
 *   bin/console data:scan-datasets                    # all providers
 *   bin/console data:scan-datasets --provider=fortepan
 *   bin/console data:scan-datasets --provider=dc --limit=10
 */
#[AsCommand('data:scan-datasets', 'Scan APP_DATA_DIR for 00_meta/dataset.yaml + pixie DBs and populate DatasetInfo registry')]
final class ScanDatasetsCommand extends DataCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only scan this provider (e.g. fortepan, dc, pp)')] ?string $provider = null,
        #[Option('Max datasets to process (0 = all)')] int $limit = 0,
        #[Option('Re-scan even if DatasetInfo already exists')] bool $force = false,
        #[Option('Only update status/counts, skip re-reading meta')] bool $statusOnly = false,
        #[Option('Pixie DB directory (defaults to APP_DATA_DIR/pixie)')] ?string $pixieDir = null,
    ): int {
        $io->title('Scanning datasets → DatasetInfo registry');

        /** @var DatasetInfoRepository $repo */
        $repo    = $this->em->getRepository(DatasetInfo::class);
        $created = $updated = $skipped = 0;
        $count   = 0;

        // ── Phase 1: Scan 00_meta/dataset.yaml files ──────────────────────────
        $root = $this->dataPaths->datasetsRoot;

        // Prefer JSON (fast), fall back to YAML (legacy)
        $jsonFiles = glob($provider ? $root.'/'.$provider.'/*/00_meta/dataset.json' : $root.'/*/*/00_meta/dataset.json') ?: [];
        $yamlFiles = glob($provider ? $root.'/'.$provider.'/*/00_meta/dataset.yaml' : $root.'/*/*/00_meta/dataset.yaml') ?: [];
        $jsonDirs  = array_map('dirname', $jsonFiles);
        $yamlOnly  = array_values(array_filter($yamlFiles, fn($f) => !in_array(dirname($f), $jsonDirs, true)));
        $files     = array_merge($jsonFiles, $yamlOnly);

        $io->text(sprintf('Phase 1: %d meta files (%d JSON, %d YAML-only) in %s', count($files), count($jsonFiles), count($yamlOnly), $root));

        foreach ($files as $metaFile) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $meta = str_ends_with($metaFile, '.json')
                ? json_decode(file_get_contents($metaFile), true, 512, JSON_THROW_ON_ERROR)
                : Yaml::parseFile($metaFile);
            $datasetKey = $meta['dataset_key'] ?? null;
            if (!$datasetKey) {
                continue;
            }

            $existing = $repo->find($datasetKey);

            if ($existing && !$force && !$statusOnly) {
                $skipped++;
                continue;
            }

            $info = $existing ?? new DatasetInfo($datasetKey);

            if (!$statusOnly) {
                $this->populateFromMeta($info, $meta, $metaFile);
            }

            if (!$existing) {
                $this->em->persist($info);
                $created++;
            } else {
                $updated++;
            }

            $count++;

            if ($count % 100 === 0) {
                $this->em->flush();
                $io->text(sprintf('  %d processed...', $count));
            }
        }

        $this->em->flush();

        // ── Phase 2: Scan pixie DB directory — match to existing DatasetInfo ──
        $pixiePath = $pixieDir ?? ($this->dataPaths->root . '/pixie');
        $dbFiles   = glob($pixiePath . '/*.db') ?: [];

        $io->text(sprintf('Phase 2: Found %d pixie .db files in %s', count($dbFiles), $pixiePath));

        $pixieUpdated = 0;
        foreach ($dbFiles as $dbFile) {
            $pixieCode  = basename($dbFile, '.db');
            // Convert pixie code back to dataset key: "fortepan_hu" → "fortepan/hu"
            // Try both underscore splits to find a matching DatasetInfo
            $datasetKey = $this->pixieCodeToDatasetKey($pixieCode, $repo);

            if (!$datasetKey) {
                // No matching meta — create a minimal DatasetInfo from the DB file
                $datasetKey = str_replace('_', '/', $pixieCode);
                $info = $repo->find($datasetKey) ?? new DatasetInfo($datasetKey);
                if (!$repo->find($datasetKey)) {
                    $this->em->persist($info);
                }
            } else {
                $info = $repo->find($datasetKey);
                if (!$info) {
                    continue; // shouldn't happen
                }
            }

            $info->pixieDbPath  = $dbFile;
            $info->pixieDbSize  = (int) filesize($dbFile);
            $pixieUpdated++;
        }

        if ($pixieUpdated > 0) {
            $this->em->flush();
        }

        // ── Phase 3: Update status for all scanned entries ────────────────────
        foreach ($repo->findAll() as $info) {
            $this->updateStatus($info);
        }
        $this->em->flush();

        $io->success(sprintf(
            'Done — created: %d, updated: %d, skipped: %d, pixie DBs matched: %d',
            $created, $updated, $skipped, $pixieUpdated
        ));

        // Show what has pixie DBs
        $withDb = $repo->createQueryBuilder('d')
            ->where('d.pixieDbPath IS NOT NULL')
            ->orderBy('d.aggregator')->addOrderBy('d.datasetKey')
            ->getQuery()->getResult();

        if ($withDb) {
            $io->section('Datasets with pixie DB (ready to browse)');
            $rows = [];
            foreach ($withDb as $info) {
                $rows[] = [
                    $info->datasetKey,
                    $info->label ?? '-',
                    $info->pixieRowCount ? number_format($info->pixieRowCount) : '?',
                    round($info->pixieDbSize / 1024) . ' KB',
                    $info->status,
                ];
            }
            $io->table(['Dataset key', 'Label', 'Rows', 'DB size', 'Status'], $rows);
        }

        return 0;
    }

    private function pixieCodeToDatasetKey(string $pixieCode, DatasetInfoRepository $repo): ?string
    {
        // Try progressively: "fortepan_hu" → "fortepan/hu", "dc_0v83gg01j" → "dc/0v83gg01j"
        $pos = strpos($pixieCode, '_');
        while ($pos !== false) {
            $candidate = substr($pixieCode, 0, $pos) . '/' . substr($pixieCode, $pos + 1);
            if ($repo->find($candidate)) {
                return $candidate;
            }
            $pos = strpos($pixieCode, '_', $pos + 1);
        }
        return null;
    }

    private function populateFromMeta(DatasetInfo $info, array $meta, string $metaFile): void
    {
        $paths = new DatasetPaths($this->dataPaths, $info->datasetKey);

        $info->label        = $meta['label'] ?? null;
        $info->description  = $meta['description'] ?? null;
        $info->aggregator   = $meta['aggregator'] ?? $info->provider();
        $info->locale       = $meta['locale']['default'] ?? null;
        $info->country      = $meta['country']['iso2'] ?? null;
        $info->contactUrl   = $meta['contact']['url'] ?? null;
        $info->rightsUri    = $meta['rights']['default_uri'] ?? null;
        $info->objCount     = (int)($meta['extras']['obj_count'] ?? 0);
        $info->meta         = $meta;
        $info->metaPath     = $metaFile;
        $info->lastScanned  = new \DateTimeImmutable();

        // Resolve paths — store absolute paths so no filesystem access needed later
        $rawFile = $paths->rawFile('obj.jsonl');
        $info->rawPath        = is_file($rawFile) ? $rawFile : null;

        $normFile = $paths->normalizeFile('obj.jsonl');
        $info->normalizedPath = is_file($normFile) ? $normFile : null;

        $profFile = $paths->profileFile('obj.profile.json');
        $info->profilePath    = is_file($profFile) ? $profFile : null;

        // Compile core list + field names from profile
        if ($info->profilePath && is_file($info->profilePath)) {
            $this->populateFromProfile($info, $info->profilePath);
        }
    }

    private function populateFromProfile(DatasetInfo $info, string $profilePath): void
    {
        try {
            $profile = json_decode(file_get_contents($profilePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        $info->normalizedCount = $profile['recordCount'] ?? null;

        // Profile is for the 'obj' core by default
        // Multi-core datasets will have multiple profile files (obj.profile.json, cat.profile.json, etc.)
        $coreName = basename(dirname(dirname($profilePath))); // derive from path if possible
        $coreName = 'obj'; // default — most datasets have a single 'obj' core

        if (!in_array($coreName, $info->cores, true)) {
            $info->cores[] = $coreName;
        }

        $info->fields[$coreName] = array_keys($profile['fields'] ?? []);

        if ($info->normalizedCount) {
            $info->lastNormalized = new \DateTimeImmutable();
        }
    }

    private function updateStatus(DatasetInfo $info): void
    {
        $info->status = match (true) {
            $info->meiliDocCount > 0    => 'indexed',
            $info->pixieRowCount > 0    => 'pixie',
            $info->hasProfile()         => 'profiled',
            $info->hasNormalized()      => 'normalized',
            $info->hasRaw()             => 'raw',
            default                     => 'discovered',
        };
    }
}
