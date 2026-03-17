<?php
declare(strict_types=1);

namespace Survos\DataBundle\Dto\Item;

use Survos\DataBundle\Metadata\ContentType;

/**
 * Base DTO for all museum collection items.
 *
 * Maps to/from:
 *   - pixie Row.data (JSON blob in SQLite)
 *   - zm Item + Value rows (via field_map.yaml)
 *   - Meilisearch document (flat array via toMeili())
 *
 * Subclass per ContentType constant to add type-specific fields,
 * define the default zm ResourceTemplate, and drive the input form.
 *
 * All field names match the normalized JSONL keys from DcSetRecordListener,
 * FortepanSetRecordListener, etc. — the same names in 20_normalize/obj.jsonl.
 */
abstract class BaseItemDto
{
    // ── Identity ──────────────────────────────────────────────────────────────

    public ?string $id          = null;
    public ?string $sourceUrl   = null;
    public ?string $contentType = null;
    public ?string $aggregator  = null;

    // ── Core DC fields (always present regardless of type) ───────────────────

    /** dcterms:title */
    public ?string $title = null;

    /** dcterms:description */
    public ?string $description = null;

    /** dcterms:date — display string, may be fuzzy ("ca. 1920") */
    public ?string $date = null;

    /** Integer year for sorting/filtering */
    public ?int $year = null;

    /** dcterms:rights */
    public ?string $rights = null;

    /** dcterms:license URI (rightsstatements.org) */
    public ?string $rightsUri = null;

    /** dcterms:accessRights — e.g. "no restrictions", "creative commons" */
    public ?string $reuseAllowed = null;

    /** dcterms:language */
    public ?array $language = null;

    /** dcterms:identifier — local accession number */
    public ?string $identifierLocal = null;

    // ── Agents ────────────────────────────────────────────────────────────────

    /** dcterms:creator — array of names */
    public ?array $creators = null;

    /** Holding institution */
    public ?string $institution = null;

    /** Collection name(s) */
    public ?array $collections = null;

    // ── Subjects ──────────────────────────────────────────────────────────────

    /** dcterms:subject — keyword/topical subjects */
    public ?array $subjects = null;

    /** dcterms:spatial — geographic subjects */
    public ?array $subjectsGeographic = null;

    // ── Geography ─────────────────────────────────────────────────────────────

    public ?string $country  = null;
    public ?string $state    = null;
    public ?string $county   = null;
    public ?string $city     = null;
    public ?float  $latitude = null;
    public ?float  $longitude= null;

    // ── Media ─────────────────────────────────────────────────────────────────

    /** IIIF Image API base URL — use for AI vision and imgProxy resizing */
    public ?string $iiifBase     = null;
    public ?string $iiifManifest = null;
    public ?string $thumbnailUrl = null;

    // ── Provenance (for zm Values) ────────────────────────────────────────────

    /** Source of this data: import | ai | ocr | human */
    public string $source = 'import';

    /** Confidence 0.0–1.0 (null = certain, i.e. human-entered) */
    public ?float $confidence = 0.7;

    // ── Class metadata (override in subclasses) ───────────────────────────────

    /**
     * The ContentType constant for this DTO class.
     * e.g. ContentType::PHOTOGRAPH, ContentType::NEWSPAPER
     */
    abstract public static function contentType(): string;

    /**
     * LOC/DCMI URI for the content type.
     * Used as zm ResourceClass and for RDF export.
     */
    public static function classUri(): ?string
    {
        return ContentType::uri(static::contentType());
    }

    /**
     * Human-readable label for the zm ResourceTemplate.
     */
    public static function classLabel(): string
    {
        return ucfirst(static::contentType());
    }

    // ── Hydration ─────────────────────────────────────────────────────────────

    /**
     * Populate from a normalized JSONL record (20_normalize/obj.jsonl).
     * Field names match the normalizer output exactly.
     */
    public static function fromNormalized(array $row): static
    {
        $dto = new static();
        foreach (get_object_vars($dto) as $prop => $_) {
            if (array_key_exists($prop, $row)) {
                $dto->$prop = $row[$prop];
            }
        }
        // Handle snake_case aliases
        $dto->id            ??= $row['id']             ?? $row['ark_id']        ?? null;
        $dto->sourceUrl     ??= $row['url']            ?? $row['page_url']      ?? null;
        $dto->contentType   ??= $row['content_type']   ?? static::contentType();
        $dto->aggregator    ??= $row['aggregator']      ?? null;
        $dto->creators      ??= $row['name_facet']     ?? $row['creators']      ?? null;
        $dto->subjects      ??= $row['subject_facet']  ?? $row['subjects']      ?? $row['tags'] ?? null;
        $dto->subjectsGeographic ??= $row['subject_geographic'] ?? null;
        $dto->identifierLocal    ??= $row['identifier_local']   ?? null;
        $dto->iiifBase      ??= $row['iiif_base']      ?? null;
        $dto->iiifManifest  ??= $row['iiif_manifest']  ?? null;
        $dto->thumbnailUrl  ??= $row['thumbnail_url']  ?? null;
        return $dto;
    }

    /**
     * Flatten to a Meilisearch document — same field names as the normalized JSONL.
     * Null values are excluded (Meilisearch handles missing fields gracefully).
     */
    public function toMeili(): array
    {
        return array_filter(
            get_object_vars($this),
            static fn($v) => $v !== null && $v !== [] && $v !== ''
        );
    }

    /**
     * Build a zm-compatible Value map keyed by dcterms: URI.
     * Used by the zm import pipeline.
     *
     * @return array<string, mixed>
     */
    public function toValueMap(): array
    {
        return array_filter([
            'dcterms:title'       => $this->title,
            'dcterms:description' => $this->description,
            'dcterms:date'        => $this->date,
            'dcterms:creator'     => $this->creators,
            'dcterms:subject'     => array_unique(array_filter(array_merge(
                $this->subjects ?? [],
                $this->subjectsGeographic ?? [],
            ))) ?: null,
            'dcterms:language'    => $this->language,
            'dcterms:rights'      => $this->rights,
            'dcterms:license'     => $this->rightsUri,
            'dcterms:identifier'  => $this->identifierLocal,
            'dcterms:source'      => $this->sourceUrl,
            'schema:latitude'     => $this->latitude,
            'schema:longitude'    => $this->longitude,
        ], static fn($v) => $v !== null && $v !== [] && $v !== '');
    }
}
