<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Scan\FilePreparer;
use Logbook\Service\Ai\Scan\PdfText;
use Logbook\Service\Ai\Scan\ScanProblem;
use Logbook\Service\Ai\Scan\Scrubber;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\FakeRenderer;
use Logbook\Tests\Support\ScanFiles;
use PHPUnit\Framework\TestCase;

/**
 * Preparing a file for the model (spec.md §7.27): text PDFs as text with
 * 11-digit runs removed before sending; scanned PDFs rendered; photos as a
 * downscaled, stripped JPEG; no renderer gives the message.
 */
final class FilePreparerTest extends TestCase
{
    private FakeRenderer $renderer;
    private FilePreparer $preparer;
    private string $path;

    protected function setUp(): void
    {
        $this->renderer = new FakeRenderer();
        $this->preparer = new FilePreparer(new PdfText(), $this->renderer);
        $this->path = (string) tempnam(sys_get_temp_dir(), 'logbook-prepare-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testATextPdfIsReadAsTextWithReferenceNumbersRemoved(): void
    {
        file_put_contents($this->path, ScanFiles::textPdf([
            'Vehicle Registration Certificate',
            'Document reference number 1234 5678 901',
            str_repeat('The keeper must tell DVLA about any change of address. ', 4),
        ]));

        $prepared = $this->preparer->prepare($this->path, 'application/pdf');

        self::assertTrue($prepared->isText());
        self::assertSame([], $prepared->images);
        self::assertStringContainsString('Vehicle Registration Certificate', (string) $prepared->text);
        self::assertStringNotContainsString('1234 5678 901', (string) $prepared->text);
        self::assertSame(0, $this->renderer->calls);
    }

    public function testAShortTextLayerIsTreatedAsAScan(): void
    {
        file_put_contents($this->path, ScanFiles::textPdf(['Total 10']));

        $prepared = $this->preparer->prepare($this->path, 'application/pdf');

        self::assertFalse($prepared->isText());
        self::assertCount(1, $prepared->images);
        self::assertSame(1, $this->renderer->calls);
    }

    public function testAScannedPdfIsRenderedToPictures(): void
    {
        file_put_contents($this->path, ScanFiles::scannedPdf(['Invoice']));

        $prepared = $this->preparer->prepare($this->path, 'application/pdf');

        self::assertCount(1, $prepared->images);
        self::assertSame('image/jpeg', $prepared->images[0]->mediaType);
    }

    public function testWithoutARendererAScannedPdfCannotBeRead(): void
    {
        $this->renderer->available = false;
        file_put_contents($this->path, ScanFiles::scannedPdf(['Invoice']));

        $prepared = $this->preparer->prepare($this->path, 'application/pdf');

        self::assertSame(ScanProblem::NoRenderer, $prepared->problem);
    }

    public function testABrokenPdfIsTreatedAsAScan(): void
    {
        file_put_contents($this->path, "%PDF-1.4\nnot really a pdf");

        $prepared = $this->preparer->prepare($this->path, 'application/pdf');

        self::assertFalse($prepared->isText());
        self::assertSame(1, $this->renderer->calls, 'no text layer to read, so it is rendered');
    }

    public function testAPhotoIsSentDownscaledAndStripped(): void
    {
        file_put_contents($this->path, ExifJpeg::make(3000, 1500, 1));

        $prepared = $this->preparer->prepare($this->path, 'image/jpeg');

        self::assertCount(1, $prepared->images);
        self::assertSame([2000, 1000], array_slice((array) getimagesizefromstring($prepared->images[0]->bytes), 0, 2));
        self::assertFalse(ExifJpeg::hasExif($prepared->images[0]->bytes));
    }

    public function testTheScrubberRemovesElevenDigitRunsOnly(): void
    {
        self::assertSame('Ref  end', Scrubber::text('Ref 12345678901 end'));
        self::assertSame('Ref  end', Scrubber::text('Ref 1234 5678 901 end'));
        self::assertSame('Test 4417 2290 1186', Scrubber::text('Test 4417 2290 1186'), 'twelve digits are not a reference');
        self::assertSame('Policy 1234567890', Scrubber::text('Policy 1234567890'), 'nor are ten');
    }
}
