<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Config;

use InvalidArgumentException;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Config\OcrConfig;
use Logbook\Support\Config\OcrEngineChoice;
use PHPUnit\Framework\TestCase;

/**
 * `OCR_ENGINE`, `TESSERACT_BINARY`, `OCR_URL` and `OCR_TIMEOUT` (spec.md §9,
 * Phase 53.1).
 */
final class OcrConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $ocr = AppSettings::fromEnv(new Env([]), '/app')->ocr;

        self::assertSame(OcrEngineChoice::Auto, $ocr->engine);
        self::assertSame('tesseract', $ocr->tesseractBinary);
        self::assertSame('', $ocr->paddleOcrUrl);
        self::assertSame(30, $ocr->timeout);
    }

    public function testEachSettingIsRead(): void
    {
        $ocr = OcrConfig::fromEnv(new Env([
            'OCR_ENGINE' => ' PaddleOCR ',
            'TESSERACT_BINARY' => '/opt/tesseract/bin/tesseract',
            'OCR_URL' => 'http://paddleocr:8080/',
            'OCR_TIMEOUT' => '900',
        ]));

        self::assertSame(OcrEngineChoice::PaddleOcr, $ocr->engine);
        self::assertSame('/opt/tesseract/bin/tesseract', $ocr->tesseractBinary);
        self::assertSame('http://paddleocr:8080', $ocr->paddleOcrUrl);
        self::assertSame(300, $ocr->timeout);
        self::assertSame(1, OcrConfig::fromEnv(new Env(['OCR_TIMEOUT' => '0']))->timeout);
    }

    public function testOffSwitchesTesseractOffAsItDoesGhostscript(): void
    {
        foreach (['off', 'OFF', 'none', 'false', '0'] as $off) {
            $settings = AppSettings::fromEnv(new Env(['TESSERACT_BINARY' => $off, 'GHOSTSCRIPT_BINARY' => $off]), '/app');
            self::assertSame('', $settings->ocr->tesseractBinary, $off);
            self::assertSame('', $settings->ai->ghostscriptBinary, $off);
        }
        self::assertSame('gs', AppSettings::fromEnv(new Env(['GHOSTSCRIPT_BINARY' => '']), '/app')->ai->ghostscriptBinary);
    }

    public function testAnUnknownEngineStopsTheApp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OCR_ENGINE "easyocr" must be one of auto, tesseract, paddleocr or off.');

        OcrConfig::fromEnv(new Env(['OCR_ENGINE' => 'easyocr']));
    }

    public function testAnAddressThatIsNotHttpStopsTheApp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OcrConfig::fromEnv(new Env(['OCR_URL' => 'file:///etc/passwd']));
    }
}
