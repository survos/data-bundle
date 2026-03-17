<?php
declare(strict_types=1);

namespace Survos\DataBundle\Dto\Item;

use Survos\DataBundle\Metadata\ContentType;

class PhotographDto extends BaseItemDto
{
    /** Photographic process e.g. "Gelatin silver print", "Albumen print" */
    public ?string $process = null;

    /** Physical format e.g. "Cabinet card", "35mm slide" */
    public ?string $format = null;

    /** Physical dimensions */
    public ?string $dimensions = null;

    /** Genre specific terms (TGM) e.g. "Portrait photographs", "Architectural photographs" */
    public ?array $genreSpecific = null;

    public static function contentType(): string { return ContentType::PHOTOGRAPH; }
}
