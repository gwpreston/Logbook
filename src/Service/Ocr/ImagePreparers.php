<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * Imagick when loaded, else GD (decided 2026-10-17, #492); GD also when
 * Imagick fails on a file.
 */
final readonly class ImagePreparers implements ImagePreparer
{
    public function __construct(
        private ImagickImagePreparer $imagick,
        private GdImagePreparer $gd,
    ) {
    }

    public function prepare(string $bytes): ?PreparedImage
    {
        if ($this->imagick->isAvailable()) {
            $prepared = $this->imagick->prepare($bytes);
            if ($prepared !== null) {
                return $prepared;
            }
        }

        return $this->gd->prepare($bytes);
    }
}
