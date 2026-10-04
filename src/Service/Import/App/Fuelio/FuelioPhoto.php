<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

/**
 * One row of Fuelio's `Pictures`: a photo in the backup's `pictures.data`
 * and the row it belongs to.
 */
final readonly class FuelioPhoto
{
    /** `Type` of a fill-up's photo, the only one the sample confirms. */
    public const int TYPE_FILL = 1;

    public function __construct(
        public int $line,
        public string $guid,
        public string $filename,
        public int $type,
        /** The `UniqueId` of the row it belongs to. */
        public string $targetId,
    ) {
    }
}
