<?php

declare(strict_types=1);

namespace Survos\DataBundle\EventListener;

use Survos\DataBundle\Service\TermSetCollector;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\DataContracts\Vocabulary\TermSetBinding;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;

/**
 * After normalization completes, scan the output JSONL and extract a
 * deduplicated vocabulary inventory split by type and language.
 *
 * Output layout in 30_terms/:
 *   genre.en.jsonl    ← {"term":"Painting","normTerm":"painting","count":87}
 *   medium.en.jsonl   ← {"term":"oil on canvas","normTerm":"oil on canvas","count":12}
 *   place.fr.jsonl    ← {"term":"Paris","normTerm":"paris","count":45}
 *   person.fr.jsonl
 *   subject.en.jsonl
 *
 * Type and language are encoded in the filename — rows carry only term data.
 * termType=genre files feed the vocab:map AI classifier.
 * All files feed folio:ingest as TermSet+Term rows.
 *
 * A term whose normTerm matches a Row.id in another folio core is a candidate
 * Relation edge rather than a plain Term — folio:ingest resolves this later.
 */
#[AsEventListener(event: ImportConvertFinishedEvent::class)]
final class VocabTermExtractorListener
{
    public function __construct(
        private readonly DataPaths $dataPaths,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
    }

    public function __invoke(ImportConvertFinishedEvent $event): void
    {
        // Use the canonical normalize stage file — some listeners (e.g. EuroSetRecordListener)
        // write directly to 20_normalize/ bypassing the pipeline's output path.
        $jsonlPath = $this->dataPaths->stageDir($event->dataset, 'normalize')
            . '/' . basename($event->jsonlPath);

        if (!is_file($jsonlPath) || filesize($jsonlPath) === 0) {
            return;
        }

        // Collect [termType][lang][normTerm] → {term, normTerm, count}
        /** @var array<string, array<string, array<string, array{term: string, normTerm: string, count: int}>>> */
        $buckets = [];

        // Explicit termset assembly for the folio bridge (termSet.jsonl + term.jsonl). Same scan,
        // but each field is intentionally declared as belonging to a named term set.
        $collector = new TermSetCollector();

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

                foreach (TermSetBinding::fields() as $termType => $fields) {
                    foreach ($fields as $field) {
                        $values = $this->scalars($row[$field] ?? null);
                        if ($values === []) {
                            continue;
                        }
                        $collector->add((string) $termType, $values);
                        foreach ($values as $term) {
                            $norm = mb_strtolower($term);
                            if (!isset($buckets[$termType][$lang][$norm])) {
                                $buckets[$termType][$lang][$norm] = ['term' => $term, 'normTerm' => $norm, 'count' => 0];
                            }
                            ++$buckets[$termType][$lang][$norm]['count'];
                        }
                    }
                }
            }
        } finally {
            fclose($fh);
        }

        if (!$buckets) {
            return;
        }

        // Bridge to folio: write the consolidated termSet.jsonl + term.jsonl that folio:ingest reads
        // (the per-type voc/*.jsonl below still feed the vocab:map AI classifier).
        if (!$collector->isEmpty()) {
            $collector->write($this->dataPaths->stageDir($event->dataset, 'normalize'), $this->fs);
        }

        $termsDir = $this->dataPaths->stageDir($event->dataset, 'terms', create: true);

        // Clear stale term files before writing fresh ones
        foreach (glob("{$termsDir}/*.jsonl") ?: [] as $stale) {
            $this->fs->remove($stale);
        }

        foreach ($buckets as $termType => $langs) {
            foreach ($langs as $lang => $terms) {
                // sort by count desc
                uasort($terms, fn ($a, $b) => $b['count'] <=> $a['count']);

                $file = "{$termsDir}/{$termType}.{$lang}.jsonl";
                $this->fs->dumpFile(
                    $file,
                    implode("\n", array_map(
                        fn ($r) => json_encode($r, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                        array_values($terms),
                    )) . "\n",
                );
            }
        }
    }

    /**
     * Extract scalar string values from a field that may be a string, a list,
     * or a list of objects (probes title/label/name/value keys).
     *
     * @return list<string>
     */
    private function scalars(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $v) {
            if (\is_string($v)) {
                $s = trim($v);
                if ('' !== $s) {
                    $out[] = $s;
                }
            } elseif (\is_array($v)) {
                foreach (['title', 'label', 'name', 'value'] as $key) {
                    if (isset($v[$key]) && \is_string($v[$key])) {
                        $s = trim($v[$key]);
                        if ('' !== $s) {
                            $out[] = $s;
                            break;
                        }
                    }
                }
            }
        }

        return $out;
    }

    private function extractLang(array $row): string
    {
        return (string) ($row[ItemField::LANGUAGE] ?? 'und');
    }
}
