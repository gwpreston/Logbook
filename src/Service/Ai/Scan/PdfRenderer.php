<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * Turns a scanned PDF's first pages into images for the vision model
 * (spec.md §7.27 *Preparing the file*).
 */
interface PdfRenderer
{
    public function isAvailable(): bool;

    /**
     * The first $pages pages as JPEG bytes, in order; [] when it fails.
     *
     * @return list<string>
     */
    public function render(string $pdfPath, int $pages, int $dpi): array;
}
