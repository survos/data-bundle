<?php

declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Survos\DataContracts\Dto\Link\LinkRow;
use Survos\DataContracts\Dto\Link\LinkTypeRow;
use Survos\DataContracts\Link\LinkFile;
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
 * Baltimore tours reached the folio as a bare stopCount for two weeks. finalize() therefore MERGES
 * through {@see LinkFile::merge()}: (rows of link types this run does not declare) + (this run's
 * rows), keyed by subjectCore:code:objectCore, which is idempotent and leaves every other
 * producer's edges alone.
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

    /** @var array<string, LinkTypeRow> LinkTypeRow::key() => type */
    private array $linkTypes = [];

    /** @var array<string, array<string, array<string,mixed>>> coreCode => slug => entity */
    private array $entities = [];

    /** @var resource|null link.jsonl write handle, opened lazily on the first link */
    private $linkHandle;

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
        $type = $this->linkTypes[LinkTypeRow::keyOf($subjectCore, $linkType, $objectCore)] ??= new LinkTypeRow($linkType, $subjectCore, $objectCore, reverseCode: $reverseCode);

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            // Skip empties and bare ids (no letters): those point at a provider's own entity core.
            if ('' === $value || 1 !== preg_match('/\p{L}/u', $value)) {
                continue;
            }

            // Personal names get parsed (person/org kind + birth/death); other entities (collections)
            // are taken as-is with the relation's fallback contentType. folio ingest requires a
            // non-empty contentType on every row.
            if ($personName) {
                $parsed = PersonName::parse($value);
                $name = $parsed['name'];
                $entity = ['id' => '', 'label' => $name, 'contentType' => $parsed['kind']];
                if (null !== $parsed['birth']) {
                    $entity['birth'] = $parsed['birth'];
                }
                if (null !== $parsed['death']) {
                    $entity['death'] = $parsed['death'];
                }
            } else {
                $name = $value;
                $entity = ['id' => '', 'label' => $name, 'contentType' => $contentType];
            }

            $slug = $this->slugger->slug($name)->lower()->toString();
            if ('' === $slug) {
                continue;
            }
            $entity['id'] = $slug;
            $this->entities[$subjectCore][$slug] = $entity;

            $this->streamLink(LinkRow::of($type, $slug, $objectId));
        }
    }

    public function isEmpty(): bool
    {
        return 0 === $this->linkCount;
    }

    /**
     * Finalise: close the streamed link.jsonl and write the entity cores + linkType.jsonl. No-op when
     * nothing was collected. (link.jsonl itself was written incrementally during add().).
     *
     * @return array{cores:int,links:int}
     */
    public function finalize(Filesystem $fs = new Filesystem()): array
    {
        if (null !== $this->linkHandle) {
            fclose($this->linkHandle);
            $this->linkHandle = null;
        }
        if (0 === $this->linkCount) {
            // Nothing collected: leave link.jsonl and linkType.jsonl exactly as they are. A
            // provider with no creator-style relations must not blank another producer's edges.
            $fs->remove($this->partFile());

            return ['cores' => 0, 'links' => 0];
        }

        foreach ($this->entities as $core => $bySlug) {
            $lines = array_map(static fn (array $e): string => json_encode($e, self::FLAGS), array_values($bySlug));
            $fs->dumpFile("{$this->normalizeDir}/{$core}.jsonl", implode("\n", $lines)."\n");
        }

        $part = $this->partFile();
        LinkFile::merge($this->normalizeDir, $this->linkTypes, (function () use ($part): \Generator {
            foreach ($this->readLines($part) as $line) {
                yield LinkRow::fromArray(json_decode($line, true, flags: \JSON_THROW_ON_ERROR));
            }
        })());
        $fs->remove($part);

        return ['cores' => \count($this->entities), 'links' => $this->linkCount];
    }

    private function partFile(): string
    {
        return "{$this->normalizeDir}/link.jsonl.part";
    }

    /** @return iterable<string> non-empty trimmed lines, or nothing when the file is absent */
    private function readLines(string $file): iterable
    {
        if (!is_file($file)) {
            return;
        }
        $handle = fopen($file, 'r');
        if (false === $handle) {
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

    private function streamLink(LinkRow $link): void
    {
        if (null === $this->linkHandle) {
            // A temp sibling, merged into link.jsonl by finalize(). Writing straight to link.jsonl
            // would truncate another producer's edges before we know which predicates are ours.
            $handle = fopen($this->partFile(), 'w');
            if (false === $handle) {
                throw new \RuntimeException(sprintf('Cannot open %s for writing.', $this->partFile()));
            }
            $this->linkHandle = $handle;
        }

        fwrite($this->linkHandle, json_encode($link->toArray(), self::FLAGS)."\n");
        ++$this->linkCount;
    }
}
