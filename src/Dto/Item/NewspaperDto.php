<?php
declare(strict_types=1);

namespace Survos\DataBundle\Dto\Item;

use Survos\DataBundle\Metadata\ContentType;

class NewspaperDto extends BaseItemDto
{
    public ?string $publisher    = null;
    public ?string $pubPlace     = null;
    public ?string $volume       = null;
    public ?string $issueNumber  = null;
    public ?string $edition      = null;
    /** Has searchable full-text pages */
    public bool    $hasTranscription = false;

    public static function contentType(): string { return ContentType::NEWSPAPER; }
}
