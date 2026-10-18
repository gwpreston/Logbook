<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ocr;

use GdImage;
use Logbook\Service\Ocr\GdImagePreparer;
use Logbook\Service\Ocr\ImagePreparers;
use Logbook\Service\Ocr\ImagickImagePreparer;
use Logbook\Service\Ocr\PageLayout;
use Logbook\Tests\Support\ExifJpeg;
use PHPUnit\Framework\TestCase;

/**
 * Preparing an image for OCR with GD (spec.md §7.27, Phase 53.1 task
 * 53.1.2): each step on a known image.
 */
final class GdImagePreparerTest extends TestCase
{
    /**
     * Rows of dark bars on light grey paper, like lines of text: $barHeight
     * high, every $pitch pixels.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param int<0, 255> $paper
     * @param int<20, 235> $ink
     */
    private static function page(
        int $width = 800,
        int $height = 1000,
        int $barHeight = 12,
        int $pitch = 40,
        int $paper = 200,
        int $ink = 90,
    ): GdImage {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(GdImage::class, $image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $paper, $paper, $paper));
        $colour = (int) imagecolorallocate($image, $ink, $ink - 20, $ink + 20);
        for ($y = 40; $y < $height - 40; $y += $pitch) {
            for ($x = 40; $x < $width - 80; $x += 70) {
                imagefilledrectangle($image, $x, $y, $x + 55, $y + $barHeight - 1, $colour);
            }
        }

        return $image;
    }

    private static function jpeg(GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 92);

        return (string) ob_get_clean();
    }

    private static function decode(string $png): GdImage
    {
        $image = imagecreatefromstring($png);
        self::assertInstanceOf(GdImage::class, $image);
        // GD's PNG is an 8-bit palette of grey levels (K82): read it as colours.
        imagepalettetotruecolor($image);

        return $image;
    }

    public function testTheResultIsAGreyscalePng(): void
    {
        $prepared = (new GdImagePreparer())->prepare(self::jpeg(self::page(barHeight: 30)));

        self::assertNotNull($prepared);
        self::assertSame('gd', $prepared->preparer);
        self::assertStringStartsWith("\x89PNG", $prepared->png);
        $image = self::decode($prepared->png);
        foreach ([[60, 50], [10, 10], [400, 300]] as [$x, $y]) {
            $rgb = imagecolorat($image, $x, $y);
            self::assertSame(($rgb >> 16) & 0xFF, $rgb & 0xFF);
            self::assertSame(($rgb >> 8) & 0xFF, $rgb & 0xFF);
        }
    }

    public function testContrastIsStretchedToTheFullRange(): void
    {
        $image = self::page(paper: 170, ink: 110);
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        [$low, $high] = GdImagePreparer::percentiles($image);
        self::assertGreaterThan(90, $low);
        self::assertLessThan(180, $high);

        GdImagePreparer::stretch($image);
        [$low, $high] = GdImagePreparer::percentiles($image);

        self::assertLessThan(15, $low);
        self::assertGreaterThan(240, $high);
    }

    public function testAnImageAlreadyFullRangeIsLeftAsItIs(): void
    {
        $image = self::page(paper: 255, ink: 20);
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        $before = imagecolorat($image, 60, 45);

        GdImagePreparer::stretch($image);

        self::assertSame($before, imagecolorat($image, 60, 45));
    }

    public function testATiltedPageIsTurnedLevel(): void
    {
        foreach ([5.0, -3.0, 7.5] as $tilt) {
            $tilted = GdImagePreparer::rotate(self::page(), $tilt);
            self::assertEqualsWithDelta(-$tilt, GdImagePreparer::skew(GdImagePreparer::thumbnail($tilted)), 0.5, (string) $tilt);
        }
        self::assertSame(0.0, GdImagePreparer::skew(self::page()));

        $prepared = (new GdImagePreparer())->prepare(self::jpeg(GdImagePreparer::rotate(self::page(barHeight: 30), 4)));
        self::assertNotNull($prepared);
        self::assertEqualsWithDelta(-4.0, $prepared->deskewAngle, 0.5);
        self::assertSame(0.0, GdImagePreparer::skew(GdImagePreparer::thumbnail(self::decode($prepared->png))));
    }

    public function testABlankPageIsNotTurned(): void
    {
        $blank = imagecreatetruecolor(300, 300);
        self::assertInstanceOf(GdImage::class, $blank);
        imagefill($blank, 0, 0, (int) imagecolorallocate($blank, 255, 255, 255));

        self::assertSame(0.0, GdImagePreparer::skew($blank));
        self::assertSame(0.0, GdImagePreparer::textHeight($blank));
    }

    public function testSmallTextIsEnlargedToAbout30Pixels(): void
    {
        self::assertSame(12.0, GdImagePreparer::textHeight(self::page()));

        $prepared = (new GdImagePreparer())->prepare(self::jpeg(self::page()));

        self::assertNotNull($prepared);
        self::assertEqualsWithDelta(2.5, $prepared->scale, 0.15);
        self::assertEqualsWithDelta(30, GdImagePreparer::textHeight(self::decode($prepared->png)), 3);
    }

    public function testLargeTextIsNotScaled(): void
    {
        $prepared = (new GdImagePreparer())->prepare(self::jpeg(self::page(barHeight: 30, pitch: 60)));

        self::assertNotNull($prepared);
        self::assertSame(1.0, $prepared->scale);
        self::assertSame([800, 1000], [$prepared->width, $prepared->height]);
    }

    public function testTheLongEdgeIsAtMost4000Pixels(): void
    {
        $prepared = (new GdImagePreparer())->prepare(self::jpeg(self::page(5000, 600, barHeight: 60, pitch: 120)));

        self::assertNotNull($prepared);
        self::assertSame(4000, $prepared->width);
        self::assertSame(0.8, $prepared->scale);
    }

    public function testTheResultIsAnEightBitPalettePng(): void
    {
        $prepared = (new GdImagePreparer())->prepare(self::jpeg(self::page(barHeight: 30)));

        self::assertNotNull($prepared);
        // Byte 25 of a PNG is its colour type: 3 is a palette of at most 8 bits.
        self::assertSame(3, ord($prepared->png[25]), 'not 24-bit truecolor (K82)');
        self::assertLessThanOrEqual(8, ord($prepared->png[24]));
    }

    public function testAPageOver12MegapixelsStaysTruecolor(): void
    {
        // K86: the palette step's copy and quantiser ran a 4000×4000 photo out of 256M.
        $page = imagecreatetruecolor(3500, 3500);
        self::assertInstanceOf(GdImage::class, $page);
        imagefill($page, 0, 0, (int) imagecolorallocate($page, 255, 255, 255));
        imagefilledrectangle($page, 200, 200, 3300, 260, (int) imagecolorallocate($page, 0, 0, 0));

        $prepared = (new GdImagePreparer())->prepare(self::jpeg($page));

        self::assertNotNull($prepared);
        self::assertNotSame(3, ord($prepared->png[25]), 'truecolor, not palette');
    }

    public function testASkewedLargePageStaysWithin4000Pixels(): void
    {
        $page = imagecreatetruecolor(4000, 2400);
        self::assertInstanceOf(GdImage::class, $page);
        imagefill($page, 0, 0, (int) imagecolorallocate($page, 255, 255, 255));
        $black = (int) imagecolorallocate($page, 0, 0, 0);
        for ($y = 200; $y < 2200; $y += 60) {
            imagefilledrectangle($page, 200, $y, 3800, $y + 20, $black);
        }
        $skewed = imagerotate($page, 6, (int) imagecolorallocate($page, 255, 255, 255));
        self::assertInstanceOf(GdImage::class, $skewed);
        $scale = 1.0;
        $fitted = GdImagePreparer::fit($skewed, 4000, $scale);

        $rotated = GdImagePreparer::rotate(GdImagePreparer::fitRotated($fitted, -6.0, $scale), -6.0);

        self::assertLessThanOrEqual(4001, max(imagesx($rotated), imagesy($rotated)), 'K75');
        self::assertLessThan(1.0, $scale);
    }

    public function testEnlargingStopsAt4000Pixels(): void
    {
        $prepared = (new GdImagePreparer())->prepare(self::jpeg(self::page(3000, 600, barHeight: 10)));

        self::assertNotNull($prepared);
        self::assertSame(4000, $prepared->width);
    }

    public function testAPhotoIsTurnedUprightByItsExifOrientation(): void
    {
        $prepared = (new GdImagePreparer())->prepare(ExifJpeg::make(400, 100, 6));

        self::assertNotNull($prepared);
        self::assertSame([100, 400], [$prepared->width, $prepared->height]);
        self::assertSame(PageLayout::Receipt, PageLayout::of($prepared->width, $prepared->height));
    }

    public function testATransparentPngIsPutOnWhitePaper(): void
    {
        $image = imagecreatetruecolor(200, 200);
        self::assertInstanceOf(GdImage::class, $image);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $prepared = (new GdImagePreparer())->prepare((string) ob_get_clean());

        self::assertNotNull($prepared);
        $white = imagecolorat(self::decode($prepared->png), 100, 100) & 0xFF;
        self::assertGreaterThanOrEqual(0xFD, $white, 'white, within the palette\'s two levels');
    }

    public function testAFileThatIsNotAnImageOrTooLargeGivesNothing(): void
    {
        self::assertNull((new GdImagePreparer())->prepare('%PDF-1.4 not an image'));
        self::assertNull((new GdImagePreparer())->prepare(''));
        self::assertNull((new GdImagePreparer())->prepare("GIF89a\x01\x00\x01\x00\x00\x00\x00;"));
        self::assertNull((new GdImagePreparer())->prepare(self::hugePngHeader()));
    }

    public function testGdIsUsedWhenImagickIsNotLoaded(): void
    {
        $imagick = new ImagickImagePreparer();
        if ($imagick->isAvailable()) {
            self::markTestSkipped('Imagick is loaded here; the Imagick test covers it.');
        }
        $prepared = (new ImagePreparers($imagick, new GdImagePreparer()))->prepare(self::jpeg(self::page()));

        self::assertNotNull($prepared);
        self::assertSame('gd', $prepared->preparer);
        self::assertNull($imagick->prepare(self::jpeg(self::page())));
    }

    public function testTheLayoutFollowsTheShape(): void
    {
        self::assertSame(PageLayout::Receipt, PageLayout::of(500, 1500));
        self::assertSame(PageLayout::Page, PageLayout::of(2480, 3508));
        self::assertSame(PageLayout::Page, PageLayout::of(0, 100));
        self::assertSame(4, PageLayout::Receipt->tesseractMode());
        self::assertSame(3, PageLayout::Page->tesseractMode());
    }

    /**
     * A PNG whose header says 10,000 × 10,000 pixels: refused before it is
     * decoded.
     */
    private static function hugePngHeader(): string
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr))
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    }
}
