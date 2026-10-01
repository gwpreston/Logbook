<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Storage;

use Logbook\Support\Storage\ImageCleaner;
use Logbook\Tests\Support\ExifJpeg;
use PHPUnit\Framework\TestCase;

final class ImageCleanerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'logbook-image-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testTheFixtureCarriesGpsAndAnOrientation(): void
    {
        file_put_contents($this->path, ExifJpeg::make());

        $exif = exif_read_data($this->path, null, true);
        self::assertIsArray($exif);
        self::assertSame(6, $exif['IFD0']['Orientation'] ?? null);
        self::assertArrayHasKey('GPS', $exif);
    }

    public function testCleaningRemovesExifIncludingGps(): void
    {
        file_put_contents($this->path, ExifJpeg::make());

        self::assertTrue(ImageCleaner::clean($this->path, 'image/jpeg'));

        $bytes = (string) file_get_contents($this->path);
        self::assertFalse(ExifJpeg::hasExif($bytes));
        $exif = @exif_read_data($this->path, null, true);
        self::assertTrue($exif === false || !isset($exif['GPS']));
    }

    public function testOrientationSixIsTurnedUpright(): void
    {
        // 40 × 20, red on the left; orientation 6 means "rotate 90° clockwise to view".
        file_put_contents($this->path, ExifJpeg::make(40, 20, 6));

        ImageCleaner::clean($this->path, 'image/jpeg');

        [$width, $height] = (array) getimagesize($this->path);
        self::assertSame([20, 40], [$width, $height]);
        $image = imagecreatefromjpeg($this->path);
        self::assertNotFalse($image);
        // The left half (red) is now the top half.
        $top = imagecolorsforindex($image, (int) imagecolorat($image, 10, 5));
        $bottom = imagecolorsforindex($image, (int) imagecolorat($image, 10, 35));
        self::assertGreaterThan(200, $top['red']);
        self::assertGreaterThan(200, $bottom['blue']);
    }

    public function testOrientationEightTurnsTheOtherWay(): void
    {
        file_put_contents($this->path, ExifJpeg::make(40, 20, 8));

        ImageCleaner::clean($this->path, 'image/jpeg');

        $image = imagecreatefromjpeg($this->path);
        self::assertNotFalse($image);
        $top = imagecolorsforindex($image, (int) imagecolorat($image, 10, 5));
        self::assertGreaterThan(200, $top['blue']);
    }

    public function testPngTransparencyIsKept(): void
    {
        $image = imagecreatetruecolor(4, 4);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $this->path);

        self::assertTrue(ImageCleaner::clean($this->path, 'image/png'));

        $clean = imagecreatefrompng($this->path);
        self::assertNotFalse($clean);
        self::assertSame(127, imagecolorsforindex($clean, (int) imagecolorat($clean, 1, 1))['alpha']);
    }

    public function testAnImageThatDoesNotDecodeIsRefused(): void
    {
        file_put_contents($this->path, "\xFF\xD8\xFF\xE0" . str_repeat("\0", 64));

        self::assertFalse(ImageCleaner::clean($this->path, 'image/jpeg'));
    }

    public function testTheDownscaledCopyFitsTheLongEdgeAndIsUpright(): void
    {
        file_put_contents($this->path, ExifJpeg::make(400, 200, 6));

        $jpeg = ImageCleaner::downscaledJpeg($this->path, 'image/jpeg', 100);

        self::assertNotNull($jpeg);
        self::assertSame([50, 100], array_slice((array) getimagesizefromstring($jpeg), 0, 2));
        self::assertFalse(ExifJpeg::hasExif($jpeg));
    }
}
