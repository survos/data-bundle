<?php

declare(strict_types=1);

namespace Survos\DataBundle\EventListener;

use Survos\DataBundle\Service\DataPaths;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;

/**
 * After normalization completes, scan the output JSONL and extract a
 * deduplicated inventory of genre/subject terms per language.
 *
 * Output: 30_terms/vocab.jsonl — one row per unique (lang, term):
 *   {"lang":"fra","term":"photographie","normTerm":"photographie","count":1523}
 *
 * This file is consumed by the vocab:map command (diff against the shared
 * vocab/{lang}/dto_map.jsonl, dispatch AI for misses, then used in enrich).
 */
#[AsEventListener(event: ImportConvertFinishedEvent::class)]
final class VocabTermExtractorListener
{
    /** Fields that may contain genre/subject vocabulary terms. */
    private const TERM_FIELDS = [
        ItemField::GENRE_SPECIFIC,
        ItemField::GENRE_BASIC,
        ItemField::TYPE,
        'subject',
        'subjects',
        'keywords',
        'classification',
        'object_type',
        'objectType',
        'category',
    ];

    public function __construct(
        private readonly DataPaths $dataPaths,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
    }

    public function __invoke(ImportConvertFinishedEvent $event): void
    {
        // Use the canonical normalize stage file — some listeners (e.g. EuroSetRecordListener)
        // write directly to 20_normalize/ rather than through the pipeline's output path,
        // so $event->jsonlPath may point to an empty file.
        $jsonlPath = $this->dataPaths->stageDir($event->dataset, 'normalize')
            . '/' . basename($event->jsonlPath);

        if (!is_file($jsonlPath) || filesize($jsonlPath) === 0) {
            return;
        }

        // Collect (lang, normTerm) → {term, count}
        /** @var array<string, array{lang: string, term: string, normTerm: string, count: int}> */
        $inventory = [];

        $fh = fopen($jsonlPath, 'r');
        if (false === $fh) {
            return;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $row = json_decode(trim($line), true);
                if (!\is_array($row)) {
                    continue;
                }

                $lang = $this->extractLang($row);

                foreach (self::TERM_FIELDS as $field) {
                    foreach ((array) ($row[$field] ?? []) as $term) {
                        $term = trim((string) $term);
                        if ('' === $term) {
                            continue;
                        }
                        $norm = mb_strtolower($term);
                        $key  = $lang . ':' . $norm;

                        if (!isset($inventory[$key])) {
                            $inventory[$key] = ['lang' => $lang, 'term' => $term, 'normTerm' => $norm, 'count' => 0];
                        }
                        ++$inventory[$key]['count'];
                    }
                }
            }
        } finally {
            fclose($fh);
        }

        if (!$inventory) {
            return;
        }

        // Sort by lang, then count desc for readability
        uasort($inventory, fn ($a, $b) => $a['lang'] <=> $b['lang'] ?: $b['count'] <=> $a['count']);

        $termsDir = $this->dataPaths->stageDir($event->dataset, 'terms', create: true);
        $out = $termsDir . '/vocab.jsonl';

        $this->fs->dumpFile(
            $out,
            implode("\n", array_map(
                fn ($row) => json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                array_values($inventory),
            )) . "\n",
        );
    }

    private function extractLang(array $row): string
    {
        $lang = $row[ItemField::LANGUAGE] ?? $row['lang'] ?? $row['language'] ?? 'und';
        // Normalise to ISO 639-3 3-letter code where possible; keep as-is otherwise.
        return strtolower(trim((string) $lang)) ?: 'und';
    }
}
