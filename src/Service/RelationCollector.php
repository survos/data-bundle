<?php

declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Survos\DataContracts\Util\PersonName;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Relation half of the vocab model (sibling of {@see TermSetCollector}): materialises an entity core
 * (e.g. `per`) from creator-style values and records typed edges to the item core, writing the
 * <core>.jsonl + linkType.jsonl + link.jsonl that folio:ingest consumes.
 *
 * Semantics: a person CREATES an object, so edges run person→object (`created`, reverse `created_by`).
 * Each value is parsed by {@see PersonName} into {id=slug, label, kind, birth?, death?} — a real
 * entity, not a string, so the enrich phase can later reconcile it to a Wikidata Q-id (and swap that
 * in as the stable PK). Pure-id / no-letter values are skipped (those reference a provider's own
 * pre-built core, e.g. Cleveland's rich creators core).
 *
 * **`link.jsonl` is shared, not this collector's file.** "Link" is overloaded — a typed edge between
 * two rows here, a hyperlink elsewhere — and the plain filename reads like *the* links when it only
 * ever held *these* links. Other producers write their own edges into the same file for the same
 * dataset (harvest's CuratescapeProvider writes story→stop `has_stop` edges with an ordinal), and
 * this class used to open it in 'w', so whichever ran last silently erased the other's rows: 31
 * Baltimore tours reached the folio as a bare stopCount for two weeks. finalize() therefore MERGES —
 * it rewrites link.jsonl as (rows whose predicate this run does not declare) + (this run's rows),
 * which is idempotent and leaves every other producer's edges alone.
 *
 * Memory: links are the unbounded dimension (one+ per row — millions on a 460k-row provider), so they
 * are STREAMED straight to a temp file as they're collected, never buffered — and the merge streams
 * both sides too, so preserving other producers' rows costs one extra pass over the file, not RAM. No in-memory dedup either:
 * a link's id is hash(type|subject|object) and FolioBulkInserter ingests with ON CONFLICT(id) DO
 * NOTHING, so duplicate edges are dropped at ingest. Only the entity map (bounded by distinct
 * persons/collections) and the tiny link-type set are held in memory.
 */
final class RelationCollector
{
    private const FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;

    /** @var array<string, array{subjectCore:string,objectCore:string,reverseCode:?string}> linkType code => def */
    private array $linkTypes = [];

    /** @var array<string, array<string, array<string,mixed>>> coreCode => slug => entity */
    private array $entities = [];

    /** @var resource|null link.jsonl write handle, opened lazily on the first link */
    private $linkHandle = null;

    private int $linkCount = 0;

    public function __construct(
        private readonly string $normalizeDir,
        private readonly SluggerInterface $slugger = new AsciiSlugger(),
    ) {
    }

    /**
     * Record that $values (creator strings on $objectId of $objectCore) are entities in $subjectCore
     * linked to it by $linkType. Materialises each value as a $subjectCore entity and STREAMS an edge
     * subject→object to link.jsonl.
     *
     * @param iterable<mixed> $values
     */
    public function add(string $subjectCore, string $linkType, ?string $reverseCode, string $objectCore, string $objectId, iterable $values, bool $personName = false, string $contentType = 'agent'): void
    {
        $this->linkTypes[$linkType] ??= ['subjectCore' => $subjectCore, 'objectCore' => $objectCore, 'reverseCode' => $reverseCode];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            // Skip empties and bare ids (no letters): those point at a provider's own entity core.
            if ($value === '' || preg_match('/\p{L}/u', $value) !== 1) {
                continue;
            }

            // Personal names get parsed (person/org kind + birth/death); other entities (collections)
            // are taken as-is with the relation's fallback contentType. folio ingest requires a
            // non-empty contentType on every row.
            if ($personName) {
                $parsed = PersonName::parse($value);
                $name = $parsed['name'];
                $entity = ['id' => '', 'label' => $name, 'contentType' => $parsed['kind']];
                if ($parsed['birth'] !== null) {
                    $entity['birth'] = $parsed['birth'];
                }
                if ($parsed['death'] !== null) {
                    $entity['death'] = $parsed['death'];
                }
            } else {
                $name = $value;
                $entity = ['id' => '', 'label' => $name, 'contentType' => $contentType];
            }

            $slug = $this->slugger->slug($name)->lower()->toString();
            if ($slug === '') {
                continue;
            }
            $entity['id'] = $slug;
            $this->entities[$subjectCore][$slug] = $entity;

            $this->streamLink([
                'predicate' => $linkType,
                'subjectCore' => $subjectCore,
                'subjectId' => $slug,
                'objectCore' => $objectCore,
                'objectId' => $objectId,
            ]);
        }
    }

    public function isEmpty(): bool
    {
        return $this->linkCount === 0;
    }

    /**
     * Finalise: close the streamed link.jsonl and write the entity cores + linkType.jsonl. No-op when
     * nothing was collected. (link.jsonl itself was written incrementally during add().)
     *
     * @return array{cores:int,links:int}
     */
    public function finalize(Filesystem $fs = new Filesystem()): array
    {
        if ($this->linkHandle !== null) {
            fclose($this->linkHandle);
            $this->linkHandle = null;
        }
        if ($this->linkCount === 0) {
            // Nothing collected: leave link.jsonl and linkType.jsonl exactly as they are. A
            // provider with no creator-style relations must not blank another producer's edges.
            $fs->remove($this->partFile());

            return ['cores' => 0, 'links' => 0];
        }

        foreach ($this->entities as $core => $bySlug) {
            $lines = array_map(static fn (array $e): string => json_encode($e, self::FLAGS), array_values($bySlug));
            $fs->dumpFile("{$this->normalizeDir}/{$core}.jsonl", implode("\n", $lines) . "\n");
        }

        $typeLines = [];
        foreach ($this->linkTypes as $code => $def) {
            $typeLines[] = json_encode([
                'code' => $code,
                'subjectCore' => $def['subjectCore'],
                'objectCore' => $def['objectCore'],
                'reverseCode' => $def['reverseCode'],
            ], self::FLAGS);
        }
        // Same merge rule as the links: keep every type this run did not declare.
        $foreignTypes = [];
        foreach ($this->readLines("{$this->normalizeDir}/linkType.jsonl") as $line) {
            $row = json_decode($line, true);
            if (\is_array($row) && !isset($this->linkTypes[(string) ($row['code'] ?? '')])) {
                $foreignTypes[] = $line;
            }
        }
        $fs->dumpFile("{$this->normalizeDir}/linkType.jsonl", implode("\n", [...$foreignTypes, ...$typeLines]) . "\n");

        $this->mergeLinks($fs);

        return ['cores' => count($this->entities), 'links' => $this->linkCount];
    }

    private function partFile(): string
    {
        return "{$this->normalizeDir}/link.jsonl.part";
    }

    /**
     * Rewrite link.jsonl as (other producers' edges) + (this run's edges), streaming both sides so a
     * multi-million-link provider never lands in memory. Ownership is exactly what this run declared
     * in $linkTypes — nothing wider — so an unknown predicate is always somebody else's and survives.
     */
    private function mergeLinks(Filesystem $fs): void
    {
        $target = "{$this->normalizeDir}/link.jsonl";
        $merged = "{$this->normalizeDir}/link.jsonl.merging";
        $out = fopen($merged, 'w');
        if ($out === false) {
            throw new \RuntimeException(sprintf('Cannot open %s for writing.', $merged));
        }

        try {
            foreach ($this->readLines($target) as $line) {
                $row = json_decode($line, true);
                if (!\is_array($row) || !isset($this->linkTypes[(string) ($row['predicate'] ?? '')])) {
                    fwrite($out, $line . "\n");
                }
            }
            $part = fopen($this->partFile(), 'r');
            if ($part === false) {
                throw new \RuntimeException(sprintf('Cannot read %s.', $this->partFile()));
            }
            try {
                stream_copy_to_stream($part, $out);
            } finally {
                fclose($part);
            }
        } finally {
            fclose($out);
        }

        $fs->rename($merged, $target, overwrite: true);
        $fs->remove($this->partFile());
    }

    /** @return iterable<string> non-empty trimmed lines, or nothing when the file is absent */
    private function readLines(string $file): iterable
    {
        if (!is_file($file)) {
            return;
        }
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (($line = trim($line)) !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,string> $link */
    private function streamLink(array $link): void
    {
        if ($this->linkHandle === null) {
            // A temp sibling, merged into link.jsonl by finalize(). Writing straight to link.jsonl
            // would truncate another producer's edges before we know which predicates are ours.
            $handle = fopen($this->partFile(), 'w');
            if ($handle === false) {
                throw new \RuntimeException(sprintf('Cannot open %s for writing.', $this->partFile()));
            }
            $this->linkHandle = $handle;
        }

        fwrite($this->linkHandle, json_encode($link, self::FLAGS) . "\n");
        $this->linkCount++;
    }
}
