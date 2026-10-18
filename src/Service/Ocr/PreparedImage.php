<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * A page image made ready for OCR (spec.md §7.27): upright, greyscale,
 * contrast stretched, deskewed and scaled. Kept apart from the file sent
 * to a vision model, and never stored.
 */
final readonly class PreparedImage
{
    public function __construct(
        public string $png,
        public int $width,
        public int $height,
        /** Degrees it was turned to straighten it; 0 when it was straight. */
        public float $deskewAngle = 0.0,
        /** How much it was scaled (1.0: not at all). */
        public float $scale = 1.0,
        /** `gd`, `imagick`, or `none` when only re-encoded (the eval's baseline). */
        public string $preparer = 'gd',
    ) {
    }
}
