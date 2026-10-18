<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Scan\PdfPageSize;
use Logbook\Tests\Support\ScanFiles;
use PHPUnit\Framework\TestCase;

/**
 * An oversized PDF page is rendered small (spec.md §7.27, K80).
 */
final class PdfPageSizeTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'logbook-pdf-size-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testAnA4PageKeepsItsResolution(): void
    {
        file_put_contents($this->path, ScanFiles::pagePdf(595, 842));

        self::assertEqualsWithDelta(11.69, PdfPageSize::largestEdgeInches($this->path, 3), 0.01);
        self::assertSame(300, PdfPageSize::dpi($this->path, 3, 300));
        self::assertSame(150, PdfPageSize::dpi($this->path, 3, 150));
    }

    public function testA200InchPageIsRenderedWithin5000Pixels(): void
    {
        file_put_contents($this->path, ScanFiles::pagePdf(14400, 14400));

        self::assertSame(25, PdfPageSize::dpi($this->path, 3, 300));
        self::assertSame(25, PdfPageSize::dpi($this->path, 3, 150));
    }

    public function testAFileThatIsNotAPdfIsLeftAlone(): void
    {
        file_put_contents($this->path, 'not a pdf');

        self::assertNull(PdfPageSize::largestEdgeInches($this->path, 3));
        self::assertSame(300, PdfPageSize::dpi($this->path, 3, 300));
    }
}
