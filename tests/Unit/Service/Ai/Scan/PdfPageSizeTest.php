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

    public function testAnInheritedMediaBoxIsRead(): void
    {
        // K80: a MediaBox on /Pages, not the page.
        file_put_contents($this->path, ScanFiles::pagePdf(14400, 14400, true));

        self::assertEqualsWithDelta(200.0, PdfPageSize::largestEdgeInches($this->path, 3), 0.01);
        self::assertSame(25, PdfPageSize::dpi($this->path, 3, 300));
    }

    public function testAUserUnitScalesThePage(): void
    {
        // K80: a 100-point page with UserUnit 50 is 5,000 points.
        file_put_contents($this->path, ScanFiles::pagePdf(100, 100, false, 50.0));

        self::assertEqualsWithDelta(69.44, PdfPageSize::largestEdgeInches($this->path, 3), 0.01);
        self::assertSame(72, PdfPageSize::dpi($this->path, 3, 300));
        file_put_contents($this->path, ScanFiles::pagePdf(100, 100, true, 50.0));
        self::assertSame(72, PdfPageSize::dpi($this->path, 3, 300), 'inherited too');
    }

    public function testAFileThatIsNotAPdfIsLeftAlone(): void
    {
        file_put_contents($this->path, 'not a pdf');

        self::assertNull(PdfPageSize::largestEdgeInches($this->path, 3));
        self::assertSame(300, PdfPageSize::dpi($this->path, 3, 300));
    }
}
