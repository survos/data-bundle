<?php
declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Symfony\Component\Filesystem\Filesystem;

use function preg_replace;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Canonical layout under APP_DATA_DIR:
 *
 *   $APP_DATA_DIR/
 *     data/<datasetKey>/
 *       00_meta/
 *       05_raw/
 *       10_extract/
 *       20_normalize/
 *       21_profile/
 *       30_terms/
 *     pixie/...
 *     runs/...
 *     cache/...
 */
final class DataPaths
{
    private ?Filesystem $fs = null;

    public function sanitizeDatasetKey(string $code): string
    {
        return $this->datasetKeyFromRef($code);
    }

    /**
     * Parse a dataset reference.
     *
     * Accepts:
     *  - provider/code (preferred)
     *  - provider-code (legacy)
     *  - code (treated as provider/code for provider-scoped dirs)
     *
     * @return array{provider:string,code:string,key:string}
     */
    public function parseDatasetRef(string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \InvalidArgumentException('Dataset cannot be empty.');
        }

        $provider = '';
        $code = '';

        if (str_contains($ref, '/')) {
            [$provider, $code] = explode('/', $ref, 2);
        } elseif (str_contains($ref, '-')) {
            [$provider, $code] = explode('-', $ref, 2);
        } else {
            $provider = $ref;
            $code = $ref;
        }

        $provider = $this->sanitizeToken($provider);
        $code = $this->sanitizeToken($code);

        $key = $provider . '-' . $code;
        return ['provider' => $provider, 'code' => $code, 'key' => $key];
    }

    public function datasetKeyFromRef(string $ref): string
    {
        return $this->parseDatasetRef($ref)['key'];
    }

    private function sanitizeToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            throw new \InvalidArgumentException('Dataset token cannot be empty.');
        }

        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $token) ?? $token;
        $safe = trim($safe, '_');

        if (
            $safe === ''
            || str_contains($safe, '..')
            || str_starts_with($safe, '/')
            || str_contains($safe, "\0")
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid token "%s".', $token));
        }

        return strtolower($safe);
    }

    /**
     * Stage aliases (semantic -> directory).
     *
     * @var array<string,string>
     */
    public readonly array $stageMap;

    public function __construct(
        public private(set) string $dataDir,
        public private(set) string $datasetRoot = 'data',
        public private(set) string $pixieRoot = 'pixie',
        public private(set) string $runsRoot = 'runs',
        public private(set) string $cacheRoot = 'cache',
        public private(set) string $zipsRoot = 'zips',
        public private(set) string $defaultObjectFilename = 'obj.jsonl',
    ) {
        // semantic aliases are convenient for callers; canonical dirs remain numeric-prefixed.
        $this->stageMap = [
            'meta'       => '00_meta',
            'raw'        => '05_raw',
            'extract'    => '10_extract',
            'normalize'  => '20_normalize',
            'normalized' => '20_normalize',
            'profile'    => '21_profile',
            'terms'      => '30_terms',

            // allow direct numeric stage keys too (identity mapping handled in stageDir()).
        ];
    }

    public string $root { get => rtrim($this->dataDir, '/'); }

    public string $datasetsRoot { get => "{$this->root}/{$this->datasetRoot}"; }
    public string $pixieRootDir { get => "{$this->root}/{$this->pixieRoot}"; }
    public string $runsRootDir { get => "{$this->root}/{$this->runsRoot}"; }
    public string $cacheRootDir { get => "{$this->root}/{$this->cacheRoot}"; }
    public string $zipsRootDir {
        get => str_starts_with($this->zipsRoot, '/')
            ? rtrim($this->zipsRoot, '/')
            : "{$this->root}/{$this->zipsRoot}";
    }

    public function filesystem(): Filesystem
    {
        return $this->fs ??= new Filesystem();
    }

    public function datasetDir(string $datasetKey): string
    {
        $parsed = $this->parseDatasetRef($datasetKey);
        return "{$this->datasetsRoot}/{$parsed['provider']}/{$parsed['code']}";
    }

    /**
     * Resolve a stage directory for a dataset.
     *
     * Accepts either:
     *  - semantic keys: raw|normalize|profile|terms|...
     *  - canonical stage dirs: 05_raw|20_normalize|...
     */
    public function stageDir(string $datasetKey, string $stage): string
    {
        $stage = trim($stage);
        if ($stage === '') {
            throw new \InvalidArgumentException('Stage cannot be empty.');
        }

        // If caller passed a canonical directory (e.g. "05_raw"), keep it.
        $dir = $stage;

        // If caller passed a semantic key (e.g. "raw"), map it.
        if (isset($this->stageMap[$stage])) {
            $dir = $this->stageMap[$stage];
        }

        return $this->datasetDir($datasetKey) . '/' . $dir;
    }

    public function normalizeRawFilename(string $filename): string
    {
        $name = trim($filename);
        if ($name === '') {
            throw new \InvalidArgumentException('Filename cannot be empty.');
        }

        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? $name;
        $name = trim($name, '_');
        if ($name === '') {
            throw new \InvalidArgumentException('Invalid filename.');
        }

        return $name;
    }

    public function rawFileFromUrl(string $datasetKey, string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $base = trim(basename($path));
        if ($base === '') {
            $base = md5($url) . '.bin';
        }

        return $this->stageDir($datasetKey, 'raw') . '/' . $this->normalizeRawFilename($base);
    }
}
