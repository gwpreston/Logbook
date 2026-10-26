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

    public function testATiltedPageComesOutLevel(): void
    {
        $level = (new ImagickImagePreparer())->prepare(self::page(30));
        $tilted = (new ImagickImagePreparer())->prepare(self::page(30, 4.0));

        self::assertNotNull($level);
        self::assertNotNull($tilted);
        // Straight rows of bars make uneven dark-pixel counts per row; a page left tilted blurs them.
        self::assertGreaterThan(0.8 * self::rowVariance($level->png), self::rowVariance($tilted->png));
        self::assertGreaterThan(3 * self::rowVariance(self::page(30, 4.0)), self::rowVariance($tilted->png));
    }

    public function testAPhotoOverTheLongEdgeIsScaledToFitAndKeepsItsShape(): void
    {
        $photo = imagecreatetruecolor(4032, 3024);
        self::assertInstanceOf(GdImage::class, $photo);
        imagefill($photo, 0, 0, (int) imagecolorallocate($photo, 210, 205, 195));
        $ink = (int) imagecolorallocate($photo, 50, 50, 50);
        for ($y = 100; $y < 2900; $y += 120) {
            imagefilledrectangle($photo, 200, $y, 3800, $y + 40, $ink);
        }
        ob_start();
        imagejpeg($photo, null, 85);
        $prepared = (new ImagickImagePreparer())->prepare((string) ob_get_clean());

        self::assertNotNull($prepared);
        self::assertSame([4000, 3000], [$prepared->width, $prepared->height]);
        self::assertEqualsWithDelta(4000 / 4032, $prepared->scale, 0.001);
    }

    private static function rowVariance(string $image): float
    {
        $gd = imagecreatefromstring($image);
        self::assertInstanceOf(GdImage::class, $gd);
        $counts = [];
        for ($y = 0; $y < imagesy($gd); $y += 2) {
            $dark = 0;
            for ($x = 0; $x < imagesx($gd); $x += 2) {
                $dark += (imagecolorat($gd, $x, $y) & 0xFF) < 140 ? 1 : 0;
            }
            $counts[] = $dark;
        }
        $mean = array_sum($counts) / count($counts);

        return array_sum(array_map(static fn (int $c): float => ($c - $mean) ** 2, $counts)) / count($counts);
    }

    public function testALevelPageIsNotResampled(): void
    {
        $prepared = (new ImagickImagePreparer())->prepare(self::page(30));

        self::assertNotNull($prepared);
        self::assertSame(0.0, $prepared->deskewAngle);
        self::assertSame([800, 1000], [$prepared->width, $prepared->height]);
    }

    public function testASmallJpegKeepsItsSizeAndABlankPageIsNotTurned(): void
    {
        $small = (new ImagickImagePreparer())->prepare(self::page(30));
        $blank = imagecreatetruecolor(300, 300);
        self::assertInstanceOf(GdImage::class, $blank);
        imagefill($blank, 0, 0, (int) imagecolorallocate($blank, 1, 2, 3));
        ob_start();
        imagepng($blank);
        $flat = (new ImagickImagePreparer())->prepare((string) ob_get_clean());

        self::assertSame([800, 1000, 1.0], [$small?->width, $small?->height, $small?->scale]);
        self::assertNotNull($flat);
        self::assertSame([300, 300, 0.0], [$flat->width, $flat->height, $flat->deskewAngle]);
    }

    public function testItIsTurnedUprightByExif(): void
    {
        $prepared = (new ImagickImagePreparer())->prepare(ExifJpeg::make(400, 100, 6));

        self::assertNotNull($prepared);
        self::assertSame([100, 400], [$prepared->width, $prepared->height]);
    }

    public function testAnAnimatedImageIsLeftToGd(): void
    {
        $frames = new \Imagick();
        foreach (['white', 'black'] as $colour) {
            $frame = new \Imagick();
            $frame->newImage(60, 40, $colour);
            $frame->setImageFormat('webp');
            $frames->addImage($frame);
        }
        $frames->setFormat('webp');
        $animated = $frames->getImagesBlob();
        self::assertSame(2, (new \Imagick())->pingImageBlob($animated) ? self::frames($animated) : 0);

        self::assertNull((new ImagickImagePreparer())->prepare($animated));
    }

    private static function frames(string $blob): int
    {
        $image = new \Imagick();
        $image->pingImageBlob($blob);

        return $image->getNumberImages();
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
