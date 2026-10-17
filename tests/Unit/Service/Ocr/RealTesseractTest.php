<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ocr;

use Logbook\Service\Ocr\GdImagePreparer;
use Logbook\Service\Ocr\OcrSampleTest;
use Logbook\Service\Ocr\PageLayout;
use Logbook\Service\Ocr\TesseractEngine;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Process\Executable;
use Logbook\Support\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Real Tesseract on the bundled sample receipt (Phase 53.1 task 53.1.4):
 * skipped where Tesseract isn't installed; CI installs it.
 */
final class RealTesseractTest extends TestCase
{
    protected function setUp(): void
    {
        if (Executable::find('tesseract') === null) {
            self::markTestSkipped('Tesseract is not installed here (CI installs it).');
        }
    }

    public function testTheSampleReceiptIsReadWithItsColumnsKept(): void
    {
        $settings = AppSettings::fromEnv(new Env([]), dirname(__DIR__, 4));
        $engine = new TesseractEngine($settings, new ProcessRunner());
        $sample = (string) file_get_contents($settings->rootDir . '/' . OcrSampleTest::SAMPLE);
        $prepared = (new GdImagePreparer())->prepare($sample);
        self::assertNotNull($prepared);

        $result = $engine->read($prepared->png, ['eng'], PageLayout::of($prepared->width, $prepared->height));

        self::assertTrue($result->ok(), (string) $result->problem?->value);
        self::assertStringStartsWith('tesseract ', $engine->status()->version);
        self::assertContains('eng', $engine->status()->languages);
        self::assertStringContainsString('MOORSIDE SERVICE STATION', $result->layoutText);
        self::assertMatchesRegularExpression('/^TOTAL {2,}GBP 48\.20$/m', $result->layoutText);
        self::assertGreaterThan(80, $result->meanConfidence);
    }
}
