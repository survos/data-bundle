<?php
declare(strict_types=1);

namespace Survos\DataBundle\Command;

use Survos\DataBundle\Service\DataPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('data:diag', 'Diagnose APP_DATA_DIR layout and show file stats (datasets or aggregators).')]
final class DataDiagCommand extends Command
{
    public function __construct(
        private readonly DataPaths $paths,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Name of dataset (e.g. "aaa") or aggregator (e.g. "smith").')]
        string $name,

        #[Option('Comma-separated unit codes for aggregator mode (e.g. "aaa,nmah"). Omit to scan all units under the aggregator directory.')]
        ?string $unit = null,
    ): int {
        $io->title('Data diagnostics');

        $root = $this->paths->root;
        $io->writeln('APP_DATA_DIR: ' . $root);

        $datasetsRoot = $this->paths->datasetsRoot;
        if (!is_dir($datasetsRoot)) {
            $io->warning(sprintf('Datasets root does not exist: %s', $datasetsRoot));
            return Command::SUCCESS;
        }

        // Determine mode: aggregator if data/<name> contains per-unit dirs and/or has 05_raw under subdirs.
        $name = strtolower(trim($name));
        $datasetDir = $this->paths->datasetDir($name);

        if (is_dir($datasetDir)) {
            return $this->diagDataset($io, $name);
        }

        $io->error(sprintf('No dataset or aggregator found for "%s". Looked for %s and %s', $name, $aggDir, $datasetDir));
        return Command::FAILURE;
    }

    private function diagAggregator(SymfonyStyle $io, string $aggregator, ?string $unitCsv): int
    {
        $io->section(sprintf('Aggregator: %s', $aggregator));

        $units = $unitCsv
            ? array_values(array_filter(array_map('trim', explode(',', strtolower($unitCsv)))))
            : $this->listImmediateSubdirs($this->paths->aggregatorDir($aggregator));

        if (!$units) {
            $io->warning('No units found under aggregator directory.');
            return Command::SUCCESS;
        }

        sort($units, SORT_NATURAL | SORT_FLAG_CASE);

        $totals = [
            'units' => count($units),
            'raw_files' => 0,
            'raw_dirs' => 0,
            'raw_lines' => 0,
        ];

        $rows = [];
        foreach ($units as $u) {
            $rawDir = $this->paths->aggregatorRawDir($aggregator, $u);

            $raw = $this->countFilesAndDirs($rawDir);
            $rawLines = 0;

            if ($io->isVeryVerbose()) {
                $rawLines = $this->countJsonlLinesInDir($rawDir);
            }

            $totals['raw_files'] += $raw['files'];
            $totals['raw_dirs']  += $raw['dirs'];
            $totals['raw_lines'] += $rawLines;

            if ($io->isVerbose()) {
                $row = [
                    strtoupper($u),
                    sprintf('05_raw: %d files', $raw['files']),
                ];
                if ($io->isVeryVerbose()) {
                    $row[] = sprintf('%d lines', $rawLines);
                }
                $rows[] = $row;
            }
        }

        $io->writeln(sprintf('05_raw: %d dirs, %d files', $totals['raw_dirs'], $totals['raw_files']));
        if ($io->isVeryVerbose()) {
            $io->writeln(sprintf('05_raw: %d total lines (.jsonl/.jsonl.gz)', $totals['raw_lines']));
        }

        if ($io->isVerbose() && $rows) {
            $headers = ['Unit', 'Raw'];
            if ($io->isVeryVerbose()) {
                $headers[] = 'Lines';
            }
            $io->table($headers, $rows);
        }

        return Command::SUCCESS;
    }

    private function diagDataset(SymfonyStyle $io, string $unit): int
    {
        $io->section(sprintf('Dataset: %s', strtoupper($unit)));

        $stages = [
            '10_extract' => $this->paths->extractDir($unit),
            '20_normalize' => $this->paths->normalizeDir($unit),
            '21_profile' => $this->paths->profileDir($unit),
            '30_terms' => $this->paths->termsDir($unit),
        ];

        $rows = [];
        foreach ($stages as $label => $dir) {
            $counts = $this->countFilesAndDirs($dir);
            $lineCount = 0;

            if ($io->isVeryVerbose()) {
                $lineCount = $this->countJsonlLinesInDir($dir);
            }

            $summary = sprintf('%d dirs, %d files', $counts['dirs'], $counts['files']);
            $row = [$label, $summary, $dir];

            if ($io->isVeryVerbose()) {
                $row[] = sprintf('%d lines', $lineCount);
            }
            $rows[] = $row;
        }

        $headers = ['Stage', 'Counts', 'Path'];
        if ($io->isVeryVerbose()) {
            $headers[] = 'Lines';
        }

        $io->table($headers, $rows);

        // Also print a compact numeric summary like your example
        $extract = $this->countFilesAndDirs($this->paths->extractDir($unit));
        $norm = $this->countFilesAndDirs($this->paths->normalizeDir($unit));
        $io->writeln(sprintf('10_extract: %d dirs, %d files', $extract['dirs'], $extract['files']));
        $io->writeln(sprintf('20_normalize: %d dirs, %d files', $norm['dirs'], $norm['files']));

        return Command::SUCCESS;
    }

    private function looksLikeAggregator(string $aggDir): bool
    {
        // Heuristic: if it has immediate subdirs that contain 05_raw, treat as aggregator.
        foreach ($this->listImmediateSubdirs($aggDir) as $unit) {
            if (is_dir($aggDir . '/' . $unit . '/05_raw')) {
                return true;
            }
        }
        return false;
    }

    private function listImmediateSubdirs(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        $dh = opendir($dir);
        if ($dh === false) {
            return [];
        }

        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $out[] = $entry;
            }
        }

        closedir($dh);
        return $out;
    }

    private function countFilesAndDirs(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['files' => 0, 'dirs' => 0];
        }

        $files = 0;
        $dirs = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $info) {
            if ($info->isDir()) {
                $dirs++;
            } else {
                $files++;
            }
        }

        return ['files' => $files, 'dirs' => $dirs];
    }

    private function countJsonlLinesInDir(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $lines = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $info) {
            if (!$info->isFile()) {
                continue;
            }

            $path = $info->getPathname();
            if (str_ends_with($path, '.jsonl')) {
                $lines += $this->countLines($path);
            } elseif (str_ends_with($path, '.jsonl.gz')) {
                $lines += $this->countGzLines($path);
            }
        }

        return $lines;
    }

    private function countLines(string $file): int
    {
        $h = fopen($file, 'rb');
        if ($h === false) {
            return 0;
        }

        $count = 0;
        while (!feof($h)) {
            $buf = fread($h, 1024 * 1024);
            if ($buf === false) {
                break;
            }
            $count += substr_count($buf, "\n");
        }
        fclose($h);

        return $count;
    }

    private function countGzLines(string $file): int
    {
        $h = gzopen($file, 'rb');
        if ($h === false) {
            return 0;
        }

        $count = 0;
        while (!gzeof($h)) {
            $buf = gzread($h, 1024 * 1024);
            if ($buf === false) {
                break;
            }
            $count += substr_count($buf, "\n");
        }
        gzclose($h);

        return $count;
    }
}
