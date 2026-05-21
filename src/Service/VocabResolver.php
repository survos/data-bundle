<?php

declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Survos\DataBundle\Repository\VocabLabelRepository;
use Survos\DataBundle\Repository\VocabMapRepository;
use Survos\DataContracts\Metadata\ContentType;
use Survos\DataContracts\Vocabulary\ItemField;

/**
 * Resolves a normalized record array to a ContentType slug and DTO class,
 * extending ContentType::fromRecord() with VocabMap DB lookups for
 * foreign-language keywords.
 *
 * Resolution order:
 *   1. ContentType::fromRecord() — fast static path, handles English + known maps
 *   2. VocabMap lookup per keyword in record language — DB cache of AI classifications
 *   3. Fallback → ContentType::OBJECT
 *
 * The listener that produces the record sets raw data only. This service is
 * called separately — during a resolve/enrich step, not during import — so
 * it never blocks the import pipeline and can be re-run as VocabMap improves.
 */
final class VocabResolver
{
    public function __construct(
        private readonly VocabMapRepository   $maps,
        private readonly VocabLabelRepository $labels,
    ) {
    }

    /**
     * Resolve a normalized record to a ContentType slug.
     *
     * @param array<string, mixed> $record  normalized record, must include lang if keywords are foreign
     */
    public function resolve(array $record): string
    {
        // Fast path: English keywords + known string maps
        $type = ContentType::fromRecord($record);
        if ($type !== ContentType::OBJECT) {
            return $type;
        }

        // DB path: look up foreign keywords via VocabMap
        $lang = $record[ItemField::LANGUAGE] ?? $record['lang'] ?? null;
        if ($lang === null || $lang === 'eng' || $lang === 'en') {
            return $type;
        }

        $candidates = array_merge(
            (array) ($record[ItemField::GENRE_SPECIFIC] ?? []),
            (array) ($record[ItemField::GENRE_BASIC]    ?? []),
            (array) ($record['keywords']                ?? []),
            (array) ($record['subject']                 ?? []),
        );

        $best = null;
        $bestConfidence = 0.0;

        foreach ($candidates as $keyword) {
            $row = $this->maps->findByLangKeyword($lang, (string) $keyword);
            if ($row === null || $row->contentType === null) {
                continue;
            }
            if ($row->confidence > $bestConfidence) {
                $bestConfidence = $row->confidence;
                $best = $row->contentType;
            }
        }

        return $best ?? ContentType::OBJECT;
    }

    /**
     * Resolve to a DTO class name (e.g. PhotographDto::class).
     *
     * @param array<string, mixed> $record
     */
    public function dtoClass(array $record): string
    {
        return ContentType::dtoClass($this->resolve($record));
    }

    /**
     * Get the display label for a ContentType slug in the given language.
     * Falls back to the slug itself if no label is cached yet.
     */
    public function label(string $contentType, string $lang): string
    {
        return $this->labels->findLabel($contentType, $lang) ?? $contentType;
    }

    /**
     * Returns ContentType slugs that still need labels generated for this language.
     *
     * @return string[]
     */
    public function unlabelledTypes(string $lang): array
    {
        return $this->labels->findUnlabelled($lang, array_keys(ContentType::URIS));
    }
}
