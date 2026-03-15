<?php

declare(strict_types=1);

namespace Survos\DataBundle\Vocabulary;

/**
 * Dublin Core Terms vocabulary (http://purl.org/dc/terms/).
 *
 * Compact prefix:localName form is used throughout the codebase and is what
 * Omeka-S, our field_map.yaml, and the zm Property entities all expect.
 *
 * Covers:
 *   - The 15 DC Elements 1.1 terms (refined in dcterms namespace)
 *   - The 40 additional dcterms-only terms
 *
 * Usage:
 *   DcTerms::TITLE->value          // → 'dcterms:title'
 *   DcTerms::TITLE->uri()          // → 'http://purl.org/dc/terms/title'
 *   DcTerms::TITLE->label()        // → 'Title'
 *   DcTerms::TITLE->localName()    // → 'title'
 *   DcTerms::fromTerm('dcterms:title') // → DcTerms::TITLE
 *
 * @see https://www.dublincore.org/specifications/dublin-core/dcmi-terms/
 */
enum DcTerms: string
{
    // ── DC Elements 1.1 refined in dcterms namespace ──────────────────────

    /** The name given to the resource. */
    case TITLE = 'dcterms:title';

    /** An entity responsible for making contributions to the resource. */
    case CONTRIBUTOR = 'dcterms:contributor';

    /** The spatial or temporal topic of the resource, or the jurisdiction. */
    case COVERAGE = 'dcterms:coverage';

    /** An entity primarily responsible for making the resource. */
    case CREATOR = 'dcterms:creator';

    /** A point or period of time associated with an event in the lifecycle of the resource. */
    case DATE = 'dcterms:date';

    /** An account of the resource. */
    case DESCRIPTION = 'dcterms:description';

    /** The file format, physical medium, or dimensions of the resource. */
    case FORMAT = 'dcterms:format';

    /** An unambiguous reference to the resource within a given context. */
    case IDENTIFIER = 'dcterms:identifier';

    /** A language of the resource. */
    case LANGUAGE = 'dcterms:language';

    /** An entity responsible for making the resource available. */
    case PUBLISHER = 'dcterms:publisher';

    /** A related resource. */
    case RELATION = 'dcterms:relation';

    /** Information about rights held in and over the resource. */
    case RIGHTS = 'dcterms:rights';

    /** A related resource from which the described resource is derived. */
    case SOURCE = 'dcterms:source';

    /** The topic of the resource. */
    case SUBJECT = 'dcterms:subject';

    /** The nature or genre of the resource. */
    case TYPE = 'dcterms:type';

    // ── dcterms-only terms (beyond DC Elements 1.1) ───────────────────────

    /** A summary of the resource. */
    case ABSTRACT = 'dcterms:abstract';

    /** Information about who can access the resource or an indication of its security status. */
    case ACCESS_RIGHTS = 'dcterms:accessRights';

    /** The method by which items are added to a collection. */
    case ACCRUAL_METHOD = 'dcterms:accrualMethod';

    /** The frequency with which items are added to a collection. */
    case ACCRUAL_PERIODICITY = 'dcterms:accrualPeriodicity';

    /** The policy governing the addition of items to a collection. */
    case ACCRUAL_POLICY = 'dcterms:accrualPolicy';

    /** An alternative name for the resource. */
    case ALTERNATIVE = 'dcterms:alternative';

    /** A class of entity for whom the resource is intended or useful. */
    case AUDIENCE = 'dcterms:audience';

    /** Date (often a range) that the resource became or will become available. */
    case AVAILABLE = 'dcterms:available';

    /** A bibliographic reference for the resource. */
    case BIBLIOGRAPHIC_CITATION = 'dcterms:bibliographicCitation';

    /** An established standard to which the described resource conforms. */
    case CONFORMS_TO = 'dcterms:conformsTo';

    /** Date of creation of the resource. */
    case CREATED = 'dcterms:created';

    /** Date of acceptance of the resource. */
    case DATE_ACCEPTED = 'dcterms:dateAccepted';

    /** Date of copyright. */
    case DATE_COPYRIGHTED = 'dcterms:dateCopyrighted';

    /** Date of submission of the resource. */
    case DATE_SUBMITTED = 'dcterms:dateSubmitted';

    /** A class of entity, defined in terms of progression through an educational or training context. */
    case EDUCATION_LEVEL = 'dcterms:educationLevel';

    /** The size or duration of the resource. */
    case EXTENT = 'dcterms:extent';

    /** A pre-existing related resource that is substantially the same as the described resource. */
    case HAS_FORMAT = 'dcterms:hasFormat';

    /** A related resource that is included either physically or logically in the described resource. */
    case HAS_PART = 'dcterms:hasPart';

    /** A related resource that is a version, edition, or adaptation of the described resource. */
    case HAS_VERSION = 'dcterms:hasVersion';

    /** A process, used to engender knowledge, attitudes and skills, that the described resource is designed to support. */
    case INSTRUCTIONAL_METHOD = 'dcterms:instructionalMethod';

    /** A related resource that is substantially the same as the described resource, but in another format. */
    case IS_FORMAT_OF = 'dcterms:isFormatOf';

    /** A related resource in which the described resource is physically or logically included. */
    case IS_PART_OF = 'dcterms:isPartOf';

    /** A related resource that references, cites, or otherwise points to the described resource. */
    case IS_REFERENCED_BY = 'dcterms:isReferencedBy';

    /** A related resource that supplants, displaces, or supersedes the described resource. */
    case IS_REPLACED_BY = 'dcterms:isReplacedBy';

    /** A related resource that requires the described resource to support its function, delivery, or coherence. */
    case IS_REQUIRED_BY = 'dcterms:isRequiredBy';

    /** A related resource of which the described resource is a version, edition, or adaptation. */
    case IS_VERSION_OF = 'dcterms:isVersionOf';

    /** Date of formal issuance of the resource. */
    case ISSUED = 'dcterms:issued';

    /** A legal document giving official permission to do something with the resource. */
    case LICENSE = 'dcterms:license';

    /** An entity that mediates access to the resource and for whose benefit the resource is intended. */
    case MEDIATOR = 'dcterms:mediator';

    /** The material or physical carrier of the resource. */
    case MEDIUM = 'dcterms:medium';

    /** Date on which the resource was changed. */
    case MODIFIED = 'dcterms:modified';

    /** A statement of any changes in ownership and custody of the resource since its creation. */
    case PROVENANCE = 'dcterms:provenance';

    /** A related resource that is referenced, cited, or otherwise pointed to by the described resource. */
    case REFERENCES = 'dcterms:references';

    /** A related resource that supplants, displaces, or supersedes the described resource. */
    case REPLACES = 'dcterms:replaces';

    /** A related resource that is required by the described resource to support its function, delivery, or coherence. */
    case REQUIRES = 'dcterms:requires';

    /** A person or organization owning or managing rights over the resource. */
    case RIGHTS_HOLDER = 'dcterms:rightsHolder';

    /** Spatial characteristics of the resource. */
    case SPATIAL = 'dcterms:spatial';

    /** A list of subunits of the resource. */
    case TABLE_OF_CONTENTS = 'dcterms:tableOfContents';

    /** Temporal characteristics of the resource. */
    case TEMPORAL = 'dcterms:temporal';

    /** Date (often a range) of validity of a resource. */
    case VALID = 'dcterms:valid';

    // ── Namespace constants ───────────────────────────────────────────────

    public const NAMESPACE_URI = 'http://purl.org/dc/terms/';
    public const PREFIX = 'dcterms';

    // ── Helpers ───────────────────────────────────────────────────────────

    /** The local name without prefix, e.g. 'title'. */
    public function localName(): string
    {
        return substr($this->value, strlen(self::PREFIX) + 1);
    }

    /** Full URI, e.g. 'http://purl.org/dc/terms/title'. */
    public function uri(): string
    {
        return self::NAMESPACE_URI . $this->localName();
    }

    /** Human-readable label derived from the case name. */
    public function label(): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $this->name)));
    }

    /** Look up a term by its compact form, e.g. 'dcterms:title'. */
    public static function fromTerm(string $term): self
    {
        return self::from($term);
    }

    /** All 55 terms as compact strings. */
    public static function allTerms(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The 15 DC Elements 1.1 terms (as dcterms: equivalents).
     * These are the terms exposed in OAI-PMH oai_dc metadata format.
     */
    public static function dcElements(): array
    {
        return [
            self::TITLE,
            self::CONTRIBUTOR,
            self::COVERAGE,
            self::CREATOR,
            self::DATE,
            self::DESCRIPTION,
            self::FORMAT,
            self::IDENTIFIER,
            self::LANGUAGE,
            self::PUBLISHER,
            self::RELATION,
            self::RIGHTS,
            self::SOURCE,
            self::SUBJECT,
            self::TYPE,
        ];
    }

    /**
     * Terms that must never be AI-extracted — must come from authoritative source data.
     */
    public static function neverExtract(): array
    {
        return [
            self::IDENTIFIER,
            self::SOURCE,
            self::RIGHTS,
            self::LICENSE,
            self::ACCESS_RIGHTS,
        ];
    }
}
