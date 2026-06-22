<?php

declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Explicit termset builder: "this list of values belongs to THIS termset — add/create them."
 *
 * The point is intentionality. Rather than implicitly hoping a field name happens to line up with
 * a termset, a normalizer states it outright — `$collector->add('pla', $row[SUBJECTS_GEOGRAPHIC])`
 * — the value-level analogue of Arrays::renameKey. Terms are deduped by slug (one term per slug),
 * counted, and the best-cased label is kept. write() emits the folio-ingestable termSet.jsonl +
 * term.jsonl pair (same shape FolioIngestService reads).
 *
 * Only term codes + their source-language values are emitted. The term-SET display name ("Place")
 * is UI chrome resolved from the set code via translation catalogs, not stored here.
 */
final class TermSetCollector
{
    /** @var array<string, array<string, array{code:string,label:string,count:int}>> setCode => slug => term */
    private array $terms = [];

    public function __construct(
        private readonly SluggerInterface $slugger = new AsciiSlugger(),
    ) {
    }

    /**
     * Declare that $values belong to the $setCode termset, merging/​counting each value's term.
     * Non-string/empty values are ignored.
     *
     * @param iterable<mixed> $values
     */
    public function add(string $setCode, iterable $values): void
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $label = trim($value);
            if ($label === '') {
                continue;
            }
            $code = $this->slugger->slug($label)->lower()->toString();
            if ($code === '') {
                continue;
            }

            $existing = $this->terms[$setCode][$code] ?? null;
            if ($existing === null) {
                $this->terms[$setCode][$code] = ['code' => $code, 'label' => $label, 'count' => 1];
                continue;
            }

            // Prefer a properly-cased label over an all-lowercase one ("Asia" beats "asia").
            if ($existing['label'] === mb_strtolower($existing['label']) && $label !== mb_strtolower($label)) {
                $existing['label'] = $label;
            }
            $existing['count']++;
            $this->terms[$setCode][$code] = $existing;
        }
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * Write termSet.jsonl + term.jsonl into $normalizeDir for folio:ingest. Terms within a set are
     * ordered by frequency (most common first).
     *
     * @return array{sets:int,terms:int}
     */
    public function write(string $normalizeDir, Filesystem $fs = new Filesystem()): array
    {
        $flags = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;

        // One set row per code that actually collected terms; label is translated from the code.
        $setLines = [];
        foreach (array_keys($this->terms) as $code) {
            $setLines[] = json_encode(['code' => $code], $flags);
        }

        $termLines = [];
        $termCount = 0;
        foreach ($this->terms as $setCode => $terms) {
            uasort($terms, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            foreach ($terms as $term) {
                $termLines[] = json_encode([
                    'termSet' => $setCode,
                    'code' => $term['code'],
                    'path' => $term['code'],
                    'label' => $term['label'],
                    'meta' => ['count' => $term['count']],
                ], $flags);
                $termCount++;
            }
        }

        $fs->dumpFile($normalizeDir . '/termSet.jsonl', implode("\n", $setLines) . "\n");
        $fs->dumpFile($normalizeDir . '/term.jsonl', implode("\n", $termLines) . "\n");

        return ['sets' => count($setLines), 'terms' => $termCount];
    }
}
