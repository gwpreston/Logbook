<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use Logbook\Service\Ai\Scan\GhostscriptRenderer;
use Logbook\Service\Ai\Scan\PdfRenderers;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\ScanFiles;

/**
 * The real renderer (spec.md §7.27): Ghostscript turns a scanned PDF's
 * pages into JPEGs. Skipped where `gs` is not installed; CI and the Docker
 * image have it. `GHOSTSCRIPT_BINARY=off` turns it off.
 */
final class GhostscriptRendererTest extends AppTestCase
{
    public function testGhostscriptRendersTheFirstPagesOfAScan(): void
    {
        $renderer = $this->service($this->createApp(), GhostscriptRenderer::class);
        if (!$renderer->isAvailable()) {
            self::markTestSkipped('Ghostscript (gs) is not installed here.');
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-gs-test-');
        file_put_contents($path, ScanFiles::scannedPdf(['A scanned invoice', 'Total GBP 10.00']));
        try {
            $pages = $renderer->render($path, 3, 100);
        } finally {
            unlink($path);
        }

        self::assertCount(1, $pages, 'one page in, one picture out');
        $size = getimagesizefromstring($pages[0]);
        self::assertIsArray($size);
        self::assertSame('image/jpeg', $size['mime']);
    }

    public function testOffTurnsGhostscriptOff(): void
    {
        $app = $this->createApp(['GHOSTSCRIPT_BINARY' => 'off']);

        self::assertFalse($this->service($app, GhostscriptRenderer::class)->isAvailable());
        $renderers = $this->service($app, PdfRenderers::class);
        self::assertSame(class_exists(\Imagick::class), $renderers->isAvailable(), 'Imagick, if loaded, is the fallback');
    }
}
