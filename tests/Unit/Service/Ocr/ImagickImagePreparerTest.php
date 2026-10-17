<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ocr;

use GdImage;
use Logbook\Service\Ocr\GdImagePreparer;
use Logbook\Service\Ocr\ImagePreparers;
use Logbook\Service\Ocr\ImagickImagePreparer;
use Logbook\Tests\Support\ExifJpeg;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * OCR preparation with Imagick (spec.md §7.27, decided 2026-10-17, #492):
 * the same steps as GD's, an 8-bit greyscale PNG out (not a dithered
 * 1-bit one), and GD when Imagick can't take the file.
 */
#[RequiresPhpExtension('imagick')]
final class ImagickImagePreparerTest extends TestCase
{
    private static function page(int $barHeight = 12, float $tilt = 0.0): string
    {
        $image = imagecreatetruecolor(800, 1000);
        self::assertInstanceOf(GdImage::class, $image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 200, 200));
        $ink = (int) imagecolorallocate($image, 90, 90, 90);
        for ($y = 40; $y < 960; $y += 40) {
            for ($x = 40; $x < 720; $x += 70) {
                imagefilledrectangle($image, $x, $y, $x + 55, $y + $barHeight - 1, $ink);
            }
        }
        if ($tilt !== 0.0) {
            $image = GdImagePreparer::rotate($image, $tilt);
        }
        ob_start();
        imagejpeg($image, null, 92);

        return (string) ob_get_clean();
    }

    public function testItGivesAnEightBitGreyscalePngWithTheContrastStretched(): void
    {
        $prepared = (new ImagickImagePreparer())->prepare(self::page(30));

        self::assertNotNull($prepared);
        self::assertSame('imagick', $prepared->preparer);
        $info = getimagesizefromstring($prepared->png);
        self::assertIsArray($info);
        self::assertSame(8, $info['bits'] ?? null);
        $image = imagecreatefromstring($prepared->png);
        self::assertInstanceOf(GdImage::class, $image);
        imagepalettetotruecolor($image);
        [$low, $high] = GdImagePreparer::percentiles($image);
        self::assertLessThan(20, $low);
        self::assertGreaterThan(235, $high);
    }

    public function testATiltedPageIsStraightenedAndSmallTextEnlarged(): void
    {
        $prepared = (new ImagickImagePreparer())->prepare(self::page(12, 4.0));

        self::assertNotNull($prepared);
        self::assertEqualsWithDelta(-4.0, $prepared->deskewAngle, 0.6);
        self::assertGreaterThan(2.0, $prepared->scale);
    }

    public function testALevelPageIsNotResampled(): void
    {
        $prepared = (new ImagickImagePreparer())->prepare(self::page(30));

        self::assertNotNull($prepared);
        self::assertSame(0.0, $prepared->deskewAngle);
        self::assertSame([800, 1000], [$prepared->width, $prepared->height]);
    }

    public function testItIsTurnedUprightByExif(): void
    {
        $prepared = (new ImagickImagePreparer())->prepare(ExifJpeg::make(400, 100, 6));

        self::assertNotNull($prepared);
        self::assertSame([100, 400], [$prepared->width, $prepared->height]);
    }

    public function testFilesGdWouldNotTakeAreRefusedAndTheChooserUsesImagick(): void
    {
        $imagick = new ImagickImagePreparer();
        self::assertNull($imagick->prepare('%PDF-1.4'));
        self::assertNull($imagick->prepare("GIF89a\x01\x00\x01\x00\x00\x00\x00;"));

        $prepared = (new ImagePreparers($imagick, new GdImagePreparer()))->prepare(self::page(30));
        self::assertSame('imagick', $prepared?->preparer);
    }
}
