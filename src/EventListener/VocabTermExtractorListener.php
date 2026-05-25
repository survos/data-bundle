<?php

declare(strict_types=1);

namespace Survos\DataBundle\EventListener;

use Survos\DatasetBundle\Service\DataPaths;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\DataContracts\Vocabulary\MuseumVocab;
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
    /**
     * termType → [normalized field keys that contribute to it].
     *
     * termType is either 'genre' (ContentType-classifiable) or a MuseumVocab code.
     * Field keys MUST be ItemField or MuseumVocab constants — never plain strings.
     *
     * Grouping rules:
     *   tec + mat → med  (technique and material are sub-types of medium)
     *   dept      → org  (department is part of organisation)
     *   keywords + subject (ItemField) → obj  (MuseumVocab subject code)
     *   creator (ItemField) → per
     */
    private const TERM_FIELDS = [
        'genre'                   => [ItemField::GENRE_SPECIFIC, ItemField::GENRE_BASIC, ItemField::TYPE],
        MuseumVocab::MEDIUM       => [MuseumVocab::MEDIUM, MuseumVocab::TECHNIQUE, MuseumVocab::MATERIAL],
        MuseumVocab::CULTURE      => [MuseumVocab::CULTURE],
        MuseumVocab::PLACE        => [MuseumVocab::PLACE],
        MuseumVocab::SUBJECT      => [MuseumVocab::SUBJECT, ItemField::KEYWORDS],
        MuseumVocab::PERSON       => [MuseumVocab::PERSON, ItemField::CREATOR],
        MuseumVocab::ORGANISATION => [MuseumVocab::ORGANISATION, MuseumVocab::DEPARTMENT],
        MuseumVocab::PERIOD       => [MuseumVocab::PERIOD],
    ];

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

                foreach (self::TERM_FIELDS as $termType => $fields) {
                    foreach ($fields as $field) {
                        foreach ($this->scalars($row[$field] ?? null) as $term) {
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
        return $row[ItemField::LANGUAGE] ?? 'und';
    }
}
