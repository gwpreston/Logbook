<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * Ghostscript, else Imagick, else nothing (spec.md §7.27): without a
 * renderer a scanned PDF asks for a photo instead.
 */
final readonly class PdfRenderers implements PdfRenderer
{
    public function __construct(
        private GhostscriptRenderer $ghostscript,
        private ImagickRenderer $imagick,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->ghostscript->isAvailable() || $this->imagick->isAvailable();
    }

    public function render(string $pdfPath, int $pages, int $dpi): array
    {
        if ($this->ghostscript->isAvailable()) {
            $images = $this->ghostscript->render($pdfPath, $pages, $dpi);
            if ($images !== []) {
                return $images;
            }
        }

        return $this->imagick->isAvailable() ? $this->imagick->render($pdfPath, $pages, $dpi) : [];
    }
}
