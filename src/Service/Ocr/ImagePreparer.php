<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * Makes a page image ready for OCR (spec.md §7.27 *Reading text from
 * images*; decided 2026-10-17, #492): Imagick when the extension is
 * loaded, else GD, which is always there. Null when the image can't be read
 * or is too large to open.
 */
interface ImagePreparer
{
    /** Image bytes (JPEG, PNG or WebP) in, a prepared PNG out. */
    public function prepare(string $bytes): ?PreparedImage;
}
