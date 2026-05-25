<?php

declare(strict_types=1);

namespace Survos\DataBundle\EventListener;

use Survos\DataContracts\Vocabulary\ItemField;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Runs after all dataset-specific listeners (priority -10).
 *
 * Sets iiif_base to the best available image URL when not already populated.
 * imgProxy uses iiif_base as the source — it works with both real IIIF
 * Image API endpoints and plain image URLs.
 */
#[AsEventListener(event: ImportConvertRowEvent::class, priority: -10)]
final class NormalizeFallbackListener
{
    public function __invoke(ImportConvertRowEvent $event): void
    {
        if ($event->row === null || $event->stage !== 'normalize') {
            return;
        }

        $row = &$event->row;

        // Only use a high-quality source URL — never a source thumbnail.
        // imgProxy generates thumbnails from iiif_base; feeding it a small
        // thumbnail would defeat the purpose of the whole pipeline.
        if (!isset($row[ItemField::IIIF_BASE])) {
            $row[ItemField::IIIF_BASE] = $row[ItemField::LARGE_IMAGE_URL] ?? null;
        }

    }
}
