<?php

declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Survos\DataContracts\Util\PersonName;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Relation half of the vocab model (sibling of {@see TermSetCollector}): materialises an entity core
 * (e.g. `per`) from creator-style values and records typed edges to the item core, then writes the
 * <core>.jsonl + linkType.jsonl + link.jsonl that folio:ingest consumes.
 *
 * Semantics: a person CREATES an object, so edges run person→object (`created`, reverse `created_by`).
 * Each value is parsed by {@see PersonName} into {id=slug, label, kind, birth?, death?} — a real
 * entity, not a string, so the enrich phase can later reconcile it to a Wikidata Q-id (and swap that
 * in as the stable PK). Pure-id / no-letter values are skipped (those reference a provider's own
 * pre-built core, e.g. Cleveland's rich creators core).
 */
final class RelationCollector
{
    /** @var array<string, array{subjectCore:string,objectCore:string,reverseCode:?string}> linkType code => def */
    private array $linkTypes = [];

    /** @var array<string, array<string, array<string,mixed>>> coreCode => slug => entity */
    private array $entities = [];

    /** @var list<array{predicate:string,subjectCore:string,subjectId:string,objectCore:string,objectId:string}> */
    private array $links = [];

    public function __construct(
        private readonly SluggerInterface $slugger = new AsciiSlugger(),
    ) {
    }

    /**
     * Record that $values (creator strings on $objectId of $objectCore) are entities in $subjectCore
     * linked to it by $linkType. Materialises each value as a $subjectCore entity and an edge
     * subject→object.
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

            $this->links[] = [
                'predicate' => $linkType,
                'subjectCore' => $subjectCore,
                'subjectId' => $slug,
                'objectCore' => $objectCore,
                'objectId' => $objectId,
            ];
        }
    }

    public function isEmpty(): bool
    {
        return $this->links === [];
    }

    /**
     * Write each entity core (<core>.jsonl), linkType.jsonl and link.jsonl into $normalizeDir.
     *
     * @return array{cores:int,links:int}
     */
    public function write(string $normalizeDir, Filesystem $fs = new Filesystem()): array
    {
        $flags = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;

        foreach ($this->entities as $core => $bySlug) {
            $lines = array_map(static fn (array $e): string => json_encode($e, $flags), array_values($bySlug));
            $fs->dumpFile("{$normalizeDir}/{$core}.jsonl", implode("\n", $lines) . "\n");
        }

        $typeLines = [];
        foreach ($this->linkTypes as $code => $def) {
            $typeLines[] = json_encode([
                'code' => $code,
                'subjectCore' => $def['subjectCore'],
                'objectCore' => $def['objectCore'],
                'reverseCode' => $def['reverseCode'],
            ], $flags);
        }
        $fs->dumpFile("{$normalizeDir}/linkType.jsonl", implode("\n", $typeLines) . "\n");

        $seen = [];
        $linkLines = [];
        foreach ($this->links as $link) {
            $key = $link['predicate'] . '|' . $link['subjectId'] . '|' . $link['objectId'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $linkLines[] = json_encode($link, $flags);
        }
        $fs->dumpFile("{$normalizeDir}/link.jsonl", implode("\n", $linkLines) . "\n");

        return ['cores' => count($this->entities), 'links' => count($linkLines)];
    }
}
