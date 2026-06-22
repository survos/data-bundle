<?php

declare(strict_types=1);

namespace Survos\DataBundle\EventListener;

use Survos\DataBundle\Service\RelationCollector;
use Survos\DataContracts\Vocabulary\RelationBinding;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;

/**
 * After normalization, materialise relation cores + edges for vocabularies flagged
 * #[VocabTerm(relation: true)] (currently `per`): scan the item rows, parse each creator value into
 * a person/agent entity, and write <core>.jsonl + linkType.jsonl + link.jsonl for folio:ingest.
 *
 * Sibling of {@see VocabTermExtractorListener} (the term-set half). Edges run subject(entity)→object(item)
 * — a person CREATES an object.
 */
#[AsEventListener(event: ImportConvertFinishedEvent::class)]
final class RelationExtractorListener
{
    public function __construct(
        private readonly DataPaths $dataPaths,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
    }

    public function __invoke(ImportConvertFinishedEvent $event): void
    {
        $relations = RelationBinding::relations();
        if ($relations === []) {
            return;
        }

        $normalizeDir = $this->dataPaths->stageDir($event->dataset, 'normalize');
        $jsonlPath = $normalizeDir . '/' . basename($event->jsonlPath);
        if (!is_file($jsonlPath) || filesize($jsonlPath) === 0) {
            return;
        }

        // The item core being scanned (e.g. obj) is the OBJECT side of every edge.
        $itemCore = basename($jsonlPath, '.jsonl');

        $collector = new RelationCollector($normalizeDir);
        $fh = fopen($jsonlPath, 'r');
        if ($fh === false) {
            return;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $row = json_decode(trim($line), true);
                if (!\is_array($row)) {
                    continue;
                }
                $itemId = $row['id'] ?? null;
                if (!is_string($itemId) || $itemId === '') {
                    continue;
                }

                foreach ($relations as $entityCore => $spec) {
                    foreach ($spec['sourceFields'] as $field) {
                        $values = $this->scalars($row[$field] ?? null);
                        if ($values !== []) {
                            $collector->add($entityCore, $spec['linkType'], $spec['reverseCode'], $itemCore, $itemId, $values, $spec['personName'], $spec['contentType']);
                        }
                    }
                }
            }
        } finally {
            fclose($fh);
        }

        // link.jsonl was streamed during the scan; finalize closes it and writes the entity cores +
        // linkType.jsonl (no-op when nothing was collected).
        $collector->finalize($this->fs);
    }

    /**
     * Flatten a field that may be a string, a list of strings, or a list of {name,label,…} objects.
     *
     * @return list<string>
     */
    private function scalars(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            if (\is_string($item)) {
                $s = trim($item);
                if ($s !== '') {
                    $out[] = $s;
                }
            } elseif (\is_array($item)) {
                foreach (['name', 'label', 'value'] as $key) {
                    if (isset($item[$key]) && \is_string($item[$key]) && trim($item[$key]) !== '') {
                        $out[] = trim($item[$key]);
                        break;
                    }
                }
            }
        }

        return $out;
    }
}
