<?php

declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Survos\DataContracts\Attribute\VocabTerm;
use Survos\DataContracts\Vocabulary\MuseumVocab;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Derives controlled-vocabulary TermSets from a dataset's profile (obj.profile.json) — no re-scan.
 *
 * Which fields become term sets is flagged declaratively via #[VocabTerm(termSet: true)] on MuseumVocab
 * (med, cul, dept, tec, mat, coll, period, epoch). The profiler already scanned the JSONL and, for
 * low-cardinality fields (distinct ≤ cap), retained the distinct values — so term values come straight
 * from the profile. High-cardinality fields (distinctCapReached) carry only a count and are skipped here
 * (they're cores/relations, not flat term sets).
 *
 * Term identity is the SLUG: values sharing a slug are one term; the label is the best/most-consistent
 * variant (prefer a properly-cased original over an all-lowercase one). Terms are single-language —
 * multilingual values are handled as translations (translation memory), not extra terms.
 *
 * Writes termSet.jsonl + term.jsonl into the normalize dir, merge-safe with any sets a dataset hand-wrote.
 */
final class TermSetExtractor
{
    public function __construct(
        private readonly SluggerInterface $slugger = new AsciiSlugger(),
    ) {}

    /**
     * @return array<string,string> MuseumVocab code => label, for fields flagged #[VocabTerm(termSet: true)].
     */
    public function termSetFields(): array
    {
        $fields = [];
        foreach ((new \ReflectionClass(MuseumVocab::class))->getReflectionConstants() as $const) {
            foreach ($const->getAttributes(VocabTerm::class) as $attribute) {
                $meta = $attribute->newInstance();
                if ($meta->termSet) {
                    $fields[(string) $const->getValue()] = $meta->label;
                }
            }
        }

        return $fields;
    }

    /**
     * Read $normalizeDir/obj.profile.json, build term sets for the flagged fields, and (re)write
     * termSet.jsonl + term.jsonl in $normalizeDir.
     *
     * @return array{sets:int,terms:int}
     */
    public function extractFromProfile(string $normalizeDir): array
    {
        $profilePath = $normalizeDir . '/obj.profile.json';
        if (!is_file($profilePath)) {
            return ['sets' => 0, 'terms' => 0];
        }

        $profile = json_decode((string) file_get_contents($profilePath), true);
        if (!is_array($profile)) {
            return ['sets' => 0, 'terms' => 0];
        }
        $fieldStats = $profile['fields'] ?? $profile;

        [$sets, $terms] = $this->loadExisting($normalizeDir);

        foreach ($this->termSetFields() as $code => $label) {
            $values = $this->profileValues($fieldStats[$code] ?? null);
            if ($values === []) {
                continue;
            }
            $sets[$code] ??= ['code' => $code, 'label' => $label];
            foreach ($values as $value) {
                $slug = $this->slug($value);
                if ($slug === '') {
                    continue;
                }
                if (!isset($terms[$code][$slug])) {
                    $terms[$code][$slug] = ['termSet' => $code, 'code' => $slug, 'path' => $slug, 'label' => $value];
                } else {
                    $terms[$code][$slug]['label'] = $this->bestLabel($terms[$code][$slug]['label'], $value);
                }
            }
        }

        $sets = array_filter($sets, static fn (array $set): bool => ($terms[$set['code']] ?? []) !== []);

        $setWriter = JsonlWriter::open($normalizeDir . '/termSet.jsonl', mode: 'w');
        foreach ($sets as $set) {
            $setWriter->write($set, (string) $set['code']);
        }
        $setWriter->finish();

        $termCount = 0;
        $termWriter = JsonlWriter::open($normalizeDir . '/term.jsonl', mode: 'w');
        foreach ($terms as $setCode => $rows) {
            if (!isset($sets[$setCode])) {
                continue;
            }
            ksort($rows);
            foreach ($rows as $slug => $term) {
                $termWriter->write($term, $setCode . ':' . $slug);
                $termCount++;
            }
        }
        $termWriter->finish();

        return ['sets' => count($sets), 'terms' => $termCount];
    }

    /**
     * Distinct values for a field from its profile stats: scalar fields expose `distinctValues`
     * (a list, retained when under the cap); array fields expose `arrayStats._elemDistinctValues`
     * (a value→true map). Returns [] when the field is high-cardinality (values not retained).
     *
     * @return list<string>
     */
    private function profileValues(mixed $stat): array
    {
        if (!is_array($stat)) {
            return [];
        }

        if (isset($stat['distinctValues']) && is_array($stat['distinctValues'])) {
            return array_values(array_map('strval', $stat['distinctValues']));
        }

        $elem = $stat['arrayStats']['_elemDistinctValues'] ?? null;
        if (is_array($elem) && $elem !== []) {
            return array_values(array_map('strval', array_keys($elem)));
        }

        return [];
    }

    /** Prefer a properly-cased variant over an all-lowercase one; otherwise keep the first seen. */
    private function bestLabel(string $current, string $candidate): string
    {
        if ($current !== mb_strtolower($current)) {
            return $current;
        }
        if ($candidate !== mb_strtolower($candidate)) {
            return $candidate;
        }

        return $current;
    }

    /**
     * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,array<string,mixed>>>}
     */
    private function loadExisting(string $normalizeDir): array
    {
        $sets = [];
        $terms = [];

        $setFile = $normalizeDir . '/termSet.jsonl';
        if (is_file($setFile)) {
            foreach (\Survos\JsonlBundle\IO\JsonlReader::open($setFile) as $set) {
                if (isset($set['code'])) {
                    $sets[(string) $set['code']] = $set;
                }
            }
        }

        $termFile = $normalizeDir . '/term.jsonl';
        if (is_file($termFile)) {
            foreach (\Survos\JsonlBundle\IO\JsonlReader::open($termFile) as $term) {
                if (isset($term['termSet'], $term['code'])) {
                    $terms[(string) $term['termSet']][(string) $term['code']] = $term;
                }
            }
        }

        return [$sets, $terms];
    }

    private function slug(string $label): string
    {
        // AsciiSlugger transliterates Unicode (accents, CJK, …) → ASCII before slugging.
        return $this->slugger->slug($label)->lower()->toString();
    }
}
