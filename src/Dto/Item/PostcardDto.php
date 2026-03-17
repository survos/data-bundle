<?php
declare(strict_types=1);

namespace Survos\DataBundle\Dto\Item;

use Survos\DataBundle\Metadata\ContentType;

class PostcardDto extends PhotographDto
{
    /** Publisher of the postcard */
    public ?string $publisher = null;

    /** Place of publication */
    public ?string $pubPlace = null;

    /** Postmark date (may differ from photograph date) */
    public ?string $postmarkDate = null;

    /** Handwritten message on reverse (from OCR) */
    public ?string $message = null;

    /** Addressee */
    public ?string $addressee = null;

    public static function contentType(): string { return ContentType::POSTCARD; }
}
