<?php
declare(strict_types=1);

namespace Survos\DataBundle\Metadata;

/**
 * Canonical content-type slugs used across ssai, zm, md, and mus.
 *
 * Each constant value is the slug used to look up a zm ResourceTemplate,
 * drive OCR decisions, and configure AI extraction prompts.
 *
 * URIs come from:
 *   - DCMI Type Vocabulary   https://purl.org/dc/dcmitype/
 *   - LCGFT                  https://id.loc.gov/authorities/genreForms/
 *   - TGM                    https://id.loc.gov/vocabulary/graphicMaterials/
 *   - MARC Genre Terms       https://id.loc.gov/vocabulary/marcgt/
 */
final class ContentType
{
    // ── Photographic / Visual ────────────────────────────────────────────────
    const PHOTOGRAPH    = 'photograph';     // TGM tgm007965 / LCGFT gf2017027258
    const POSTCARD      = 'postcard';       // TGM tgm008060 / marcgt postcard
    const STEREOGRAPH   = 'stereograph';    // TGM tgm009325
    const SLIDE         = 'slide';          // TGM tgm009126 (lantern slides, 35mm)
    const NEGATIVE      = 'negative';       // TGM tgm006437 (film/glass/nitrate)
    const PRINT         = 'print';          // TGM tgm007912 (photomechanical, engraving, etc.)
    const DRAWING       = 'drawing';        // LCGFT gf2017027085
    const PAINTING      = 'painting';       // LCGFT gf2017027245
    const POSTER        = 'poster';         // TGM tgm008100

    // ── Cartographic ────────────────────────────────────────────────────────
    const MAP           = 'map';            // LCGFT gf2011026055 / marcgt map
    const ATLAS         = 'atlas';          // marcgt atlas
    const GLOBE         = 'globe';          // marcgt globe

    // ── Textual ──────────────────────────────────────────────────────────────
    const NEWSPAPER     = 'newspaper';      // LCGFT gf2014026139 / marcgt newspaper
    const PERIODICAL    = 'periodical';     // LCGFT gf2014026156 / marcgt periodical
    const MANUSCRIPT    = 'manuscript';     // LCGFT gf2022026088 / marcgt manuscript
    const CORRESPONDENCE= 'correspondence'; // LCGFT gf2014026051
    const BOOK          = 'book';           // marcgt book
    const DOCUMENT      = 'document';       // dcmitype:Text (generic)
    const EPHEMERA      = 'ephemera';       // LCGFT gf2014026093

    // ── Audio / Moving Image ─────────────────────────────────────────────────
    const FILM          = 'film';           // dcmitype:MovingImage
    const AUDIO         = 'audio';          // dcmitype:Sound

    // ── Object / Artifact ────────────────────────────────────────────────────
    const OBJECT        = 'object';         // dcmitype:PhysicalObject (fallback)

    // ── LOC URIs for RDF / zm Vocabulary interop ─────────────────────────────
    const URIS = [
        self::PHOTOGRAPH    => 'http://id.loc.gov/vocabulary/graphicMaterials/tgm007965',
        self::POSTCARD      => 'http://id.loc.gov/vocabulary/graphicMaterials/tgm008060',
        self::STEREOGRAPH   => 'http://id.loc.gov/vocabulary/graphicMaterials/tgm009325',
        self::SLIDE         => 'http://id.loc.gov/vocabulary/graphicMaterials/tgm009126',
        self::NEGATIVE      => 'http://id.loc.gov/vocabulary/graphicMaterials/tgm006437',
        self::PRINT         => 'http://id.loc.gov/vocabulary/graphicMaterials/tgm007912',
        self::DRAWING       => 'http://id.loc.gov/authorities/genreForms/gf2017027085',
        self::PAINTING      => 'http://id.loc.gov/authorities/genreForms/gf2017027245',
        self::POSTER        => 'http://id.loc.gov/vocabulary/graphicMaterials/tgm008100',
        self::MAP           => 'http://id.loc.gov/authorities/genreForms/gf2011026055',
        self::ATLAS         => 'http://id.loc.gov/vocabulary/marcgt/atlas',
        self::GLOBE         => 'http://id.loc.gov/vocabulary/marcgt/globe',
        self::NEWSPAPER     => 'http://id.loc.gov/authorities/genreForms/gf2014026139',
        self::PERIODICAL    => 'http://id.loc.gov/authorities/genreForms/gf2014026156',
        self::MANUSCRIPT    => 'http://id.loc.gov/authorities/genreForms/gf2022026088',
        self::CORRESPONDENCE=> 'http://id.loc.gov/authorities/genreForms/gf2014026051',
        self::BOOK          => 'http://id.loc.gov/vocabulary/marcgt/book',
        self::DOCUMENT      => 'http://purl.org/dc/dcmitype/Text',
        self::EPHEMERA      => 'http://id.loc.gov/authorities/genreForms/gf2014026093',
        self::FILM          => 'http://purl.org/dc/dcmitype/MovingImage',
        self::AUDIO         => 'http://purl.org/dc/dcmitype/Sound',
        self::OBJECT        => 'http://purl.org/dc/dcmitype/PhysicalObject',
    ];

    /**
     * Whether this content type benefits from OCR.
     * Drives the AI pipeline decision: run OCR → text extraction, or skip.
     */
    const OCR_TYPES = [
        self::NEWSPAPER,
        self::MANUSCRIPT,
        self::CORRESPONDENCE,
        self::DOCUMENT,
        self::POSTCARD,      // often has handwritten text on reverse
        self::EPHEMERA,
        self::BOOK,
        self::PERIODICAL,
        self::MAP,           // often has labels/text
        self::POSTER,
    ];

    /**
     * Whether this content type benefits from visual AI description.
     */
    const VISUAL_AI_TYPES = [
        self::PHOTOGRAPH,
        self::POSTCARD,
        self::STEREOGRAPH,
        self::NEGATIVE,
        self::PRINT,
        self::DRAWING,
        self::PAINTING,
        self::MAP,
        self::POSTER,
        self::SLIDE,
        self::OBJECT,
    ];

    // ── Incoming field maps ───────────────────────────────────────────────────

    /**
     * Maps DC genre_specific values (TGM terms) to ContentType constants.
     * genre_specific is the most precise — check it first.
     * Synonyms / alternate spellings are included.
     */
    const GENRE_SPECIFIC_MAP = [
        // Photographs
        'photographic prints'     => self::PHOTOGRAPH,
        'portrait photographs'    => self::PHOTOGRAPH,
        'group portraits'         => self::PHOTOGRAPH,
        'architectural photographs' => self::PHOTOGRAPH,
        'travel photography'      => self::PHOTOGRAPH,
        'cabinet photographs'     => self::PHOTOGRAPH,
        'cartes de visite'        => self::PHOTOGRAPH,
        // Postcards
        'postcards'               => self::POSTCARD,
        'advertising cards'       => self::POSTCARD,
        // Stereographs
        'stereographs'            => self::STEREOGRAPH,
        // Slides
        'lantern slides'          => self::SLIDE,
        'slides'                  => self::SLIDE,
        // Negatives (all types)
        'film negatives'          => self::NEGATIVE,
        'glass negatives'         => self::NEGATIVE,
        'nitrate negatives'       => self::NEGATIVE,
        'dry plate negatives'     => self::NEGATIVE,
        'negatives'               => self::NEGATIVE,
        // Prints (non-photo)
        'lithographs'             => self::PRINT,
        'etchings'                => self::PRINT,
        'engravings'              => self::PRINT,
        'chromolithographs'       => self::PRINT,
        'drypoints'               => self::PRINT,
        'woodcuts'                => self::PRINT,
        'photomechanical prints'  => self::PRINT,
        'periodical illustrations' => self::PRINT,
        'portrait prints'         => self::PRINT,
        'rotogravures'            => self::PRINT,
        'albumen prints'          => self::PHOTOGRAPH,
        'gelatin silver prints'   => self::PHOTOGRAPH,
        // Maps
        'topographic maps'        => self::MAP,
        'nautical charts'         => self::MAP,
        'wall maps'               => self::MAP,
        'world maps'              => self::MAP,
        'military maps'           => self::MAP,
        'manuscript maps'         => self::MAP,
        'pictorial maps'          => self::MAP,
        'thematic maps'           => self::MAP,
        // Documents/text
        'broadsides'              => self::DOCUMENT,
        'receipts (acknowledgments)' => self::CORRESPONDENCE,
        'lists'                   => self::DOCUMENT,
        'clippings'               => self::DOCUMENT,
        'anti-slavery newspapers' => self::NEWSPAPER,
        // Plans/drawings
        'planning drawings'       => self::DRAWING,
        'architectural drawings'  => self::DRAWING,
        // Caricatures
        'caricatures'             => self::PRINT,
    ];

    /**
     * Maps DC genre_basic values (LCGFT terms) to ContentType constants.
     * Fallback when genre_specific doesn't match.
     */
    const GENRE_BASIC_MAP = [
        'photographs'    => self::PHOTOGRAPH,
        'photo'          => self::PHOTOGRAPH,  // synonym
        'foto'           => self::PHOTOGRAPH,  // synonym (Spanish/German)
        'picture'        => self::PHOTOGRAPH,  // synonym
        'cards'          => self::POSTCARD,    // DC uses 'Cards' for postcard collections
        'maps'           => self::MAP,
        'newspapers'     => self::NEWSPAPER,
        'manuscripts'    => self::MANUSCRIPT,
        'correspondence' => self::CORRESPONDENCE,
        'prints'         => self::PRINT,
        'drawings'       => self::DRAWING,
        'paintings'      => self::PAINTING,
        'posters'        => self::POSTER,
        'ephemera'       => self::EPHEMERA,
        'books'          => self::BOOK,
        'periodicals'    => self::PERIODICAL,
        'sound recordings' => self::AUDIO,
        'motion pictures'  => self::FILM,
        'objects'          => self::OBJECT,
        'art objects'      => self::OBJECT,
        'albums (books)'   => self::BOOK,
        'musical notation' => self::DOCUMENT,
        'music'            => self::AUDIO,
    ];

    /**
     * Maps DC type_of_resource values (DCMI Type) to ContentType constants.
     * Final fallback when neither genre_specific nor genre_basic matches.
     */
    const TYPE_OF_RESOURCE_MAP = [
        'still image'    => self::PHOTOGRAPH,
        'still images'   => self::PHOTOGRAPH,
        'text'           => self::DOCUMENT,
        'cartographic'   => self::MAP,
        'moving image'   => self::FILM,
        'moving images'  => self::FILM,
        'audio'          => self::AUDIO,
        'sound recording'=> self::AUDIO,
        'sound recordings'=> self::AUDIO,
        'notated music'  => self::DOCUMENT,
        'manuscript'     => self::MANUSCRIPT,
        'mixed material' => self::DOCUMENT,
        'artifact'       => self::OBJECT,
        'software'       => self::DOCUMENT,
        'dataset'        => self::DOCUMENT,
    ];

    /**
     * Derive a canonical ContentType slug from DC normalized record attributes.
     *
     * Resolution order (most specific wins):
     *   1. genre_specific (TGM — e.g. "Postcards", "Film negatives")
     *   2. genre_basic    (LCGFT — e.g. "Photographs", "Maps")
     *   3. type_of_resource (DCMI Type — e.g. "Still image", "Text")
     *
     * Returns self::OBJECT as the ultimate fallback.
     */
    public static function fromDcAttrs(array $attrs): string
    {
        // 1. genre_specific — most precise
        foreach ((array)($attrs['genre_specific'] ?? []) as $g) {
            $key = strtolower(trim((string)$g));
            if (isset(self::GENRE_SPECIFIC_MAP[$key])) {
                return self::GENRE_SPECIFIC_MAP[$key];
            }
        }

        // 2. genre_basic
        foreach ((array)($attrs['genre_basic'] ?? []) as $g) {
            $key = strtolower(trim((string)$g));
            if (isset(self::GENRE_BASIC_MAP[$key])) {
                return self::GENRE_BASIC_MAP[$key];
            }
        }

        // 3. type_of_resource
        $typeRaw = $attrs['type_of_resource'] ?? '';
        $type    = strtolower(trim(is_array($typeRaw) ? ($typeRaw[0] ?? '') : (string)$typeRaw));
        if (isset(self::TYPE_OF_RESOURCE_MAP[$type])) {
            return self::TYPE_OF_RESOURCE_MAP[$type];
        }

        return self::OBJECT;
    }

    /**
     * Does this content type warrant running OCR?
     */
    public static function needsOcr(string $contentType): bool
    {
        return in_array($contentType, self::OCR_TYPES, true);
    }

    /**
     * Does this content type warrant visual AI description?
     */
    public static function needsVisualAi(string $contentType): bool
    {
        return in_array($contentType, self::VISUAL_AI_TYPES, true);
    }

    /**
     * Return the authoritative LOC/DCMI URI for a content type slug.
     */
    public static function uri(string $contentType): ?string
    {
        return self::URIS[$contentType] ?? null;
    }
}
