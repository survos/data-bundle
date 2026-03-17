<?php
declare(strict_types=1);

namespace Survos\DataBundle\Dto\Item;

use Survos\DataBundle\Metadata\ContentType;

class MapDto extends BaseItemDto
{
    public ?string $scale       = null;
    public ?string $projection  = null;
    public ?string $pubPlace    = null;
    public ?string $publisher   = null;
    public ?string $dimensions  = null;

    public static function contentType(): string { return ContentType::MAP; }
}
