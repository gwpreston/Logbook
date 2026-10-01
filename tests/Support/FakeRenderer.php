<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Service\Ai\Scan\PdfRenderer;

/**
 * A PDF renderer the scan tests control: available or not, and what it
 * renders (a picture of some lines per page).
 */
final class FakeRenderer implements PdfRenderer
{
    public bool $available = true;
    public int $calls = 0;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function render(string $pdfPath, int $pages, int $dpi): array
    {
        $this->calls++;

        return $this->available ? [ScanFiles::render(['A scanned page'], 600, 850)] : [];
    }
}
