<?php

declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Survos\DataContracts\Dto\Link\LinkRow;
use Survos\DataContracts\Dto\Link\LinkTypeRow;
use Survos\DataContracts\Link\LinkFile;
use Survos\DataContracts\Metadata\ContentType;
use Survos\DataContracts\Util\PersonName;
use Survos\DataContracts\Vocabulary\Core;
use Survos\DataContracts\Vocabulary\ItemField;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Relation half of the vocab model (sibling of {@see TermSetCollector}): materialises an entity core
 * (e.g. `per`) from creator-style values and records typed edges to the item core, writing the
 * <core>.jsonl + linkType.jsonl + link.jsonl that folio:ingest consumes.
 *
 * Semantics: a person or organization CREATES an object, so edges run agent→object (`created`, reverse `created_by`).
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
            $entityCore = $subjectCore;
            if ($personName) {
                // Creator is a role, not an entity kind. Own both signatures so a rebuild
                // also removes the old per->item edges when every creator is an organization.
                foreach ([Core::PERSON, Core::ORGANISATION] as $core) {
                    $this->linkTypes[LinkTypeRow::keyOf($core, $linkType, $objectCore)] ??= new LinkTypeRow($linkType, $core, $objectCore, reverseCode: $reverseCode);
                }
                $parsed = PersonName::parse($value);
                $entityCore = match ($parsed['kind']) {
                    ContentType::PERSON => Core::PERSON,
                    ContentType::ORGANIZATION, Core::ORGANISATION => Core::ORGANISATION,
                    default => throw new \UnexpectedValueException('Unsupported creator kind: '.$parsed['kind']),
                };
                $name = $parsed['name'];
                $entity = [ItemField::ID => '', 'label' => $name, ItemField::CONTENT_TYPE => Core::PERSON === $entityCore ? ContentType::PERSON : ContentType::ORGANIZATION];
                if (null !== $parsed['birth']) {
                    $entity['birth'] = $parsed['birth'];
                }
                if (null !== $parsed['death']) {
                    $entity['death'] = $parsed['death'];
                }
            } else {
                $name = $value;
                $entity = [ItemField::ID => '', 'label' => $name, ItemField::CONTENT_TYPE => $contentType];
            }

            $slug = $this->slugger->slug($name)->lower()->toString();
            if ('' === $slug) {
                continue;
            }
            $entity[ItemField::ID] = $slug;
            $this->entities[$entityCore][$slug] = $entity;

            $type = $this->linkTypes[LinkTypeRow::keyOf($entityCore, $linkType, $objectCore)] ??= new LinkTypeRow($linkType, $entityCore, $objectCore, reverseCode: $reverseCode);
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

        $this->writeEntities($fs);

        $part = $this->partFile();
        LinkFile::merge($this->normalizeDir, $this->linkTypes, (function () use ($part): \Generator {
            foreach ($this->readLines($part) as $line) {
                yield LinkRow::fromArray(json_decode($line, true, flags: \JSON_THROW_ON_ERROR));
            }
        })());
        $fs->remove($part);

        return ['cores' => \count($this->entities), 'links' => $this->linkCount];
    }

    /** Replace entities owned by this scan, preserving other cores' creators and provider rows. */
    private function writeEntities(Filesystem $fs): void
    {
        $previous = $retained = [];
        foreach (LinkFile::readLinks($this->normalizeDir) as $link) {
            if (isset($this->linkTypes[$link->typeKey()])) {
                $previous[$link->subjectCore][$link->subjectId] = true;
            } else {
                $retained[$link->subjectCore][$link->subjectId] = true;
            }
        }
        foreach (array_unique(array_map(static fn (LinkTypeRow $type): string => $type->subjectCore, $this->linkTypes)) as $core) {
            $target = "{$this->normalizeDir}/{$core}.jsonl";
            $spellings = array_values(array_filter([$target, $target.'.gz'], 'is_file'));
            usort($spellings, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
            $entities = [];
            if ([] !== $spellings) {
                foreach ($this->readLines($spellings[0]) as $line) {
                    $entity = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                    $id = $entity[ItemField::ID];
                    if (!isset($previous[$core][$id]) || isset($retained[$core][$id])) {
                        $entities[$id] = $entity;
                    }
                }
            }
            foreach ($this->entities[$core] ?? [] as $id => $entity) {
                $entities[$id] = $entity;
            }
            $lines = array_map(static fn (array $e): string => json_encode($e, self::FLAGS | \JSON_THROW_ON_ERROR), array_values($entities));
            // Empty is intentional: remove stale generated people after an org-only rebuild.
            $fs->dumpFile($target, [] === $lines ? '' : implode("\n", $lines)."\n");
            $fs->remove($target.'.gz');
            foreach ([$target, $target.'.gz'] as $file) {
                foreach (['.idx.json', '.sidecar.json', '.index.json', '.db'] as $suffix) {
                    $fs->remove($file.$suffix);
                }
            }
        }
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
        $handle = gzopen($file, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $file));
        }
        try {
            while (($line = gzgets($handle)) !== false) {
                if (($line = trim($line)) !== '') {
                    yield $line;
                }
            }
        } finally {
            gzclose($handle);
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
