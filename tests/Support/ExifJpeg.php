<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

/**
 * Builds a phone-style JPEG for tests: a real image with an EXIF block
 * carrying an orientation and a GPS position, as a receipt photographed on
 * the driveway would have.
 */
final class ExifJpeg
{
    /**
     * A $width × $height JPEG, left half red and right half blue, with EXIF
     * Orientation $orientation and a GPS latitude of 54° 35' N.
     */
    public static function make(int $width = 40, int $height = 20, int $orientation = 6): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string) ob_get_clean();

        return self::withExif($jpeg, $orientation);
    }

    /**
     * $jpeg with an APP1 EXIF segment inserted after its start marker.
     */
    public static function withExif(string $jpeg, int $orientation): string
    {
        $entry = static fn (int $tag, int $type, int $count, string $value): string
            => pack('vvV', $tag, $type, $count) . str_pad($value, 4, "\0");

        // IFD0 at 8: Orientation and a pointer to the GPS IFD at 38.
        $ifd0 = pack('v', 2)
            . $entry(0x0112, 3, 1, pack('v', $orientation))
            . $entry(0x8825, 4, 1, pack('V', 38))
            . pack('V', 0);
        // GPS IFD at 38: latitude ref "N" and the latitude, three rationals at 68.
        $gps = pack('v', 2)
            . $entry(0x0001, 2, 2, "N\0")
            . $entry(0x0002, 5, 3, pack('V', 68))
            . pack('V', 0);
        $rationals = pack('VVVVVV', 54, 1, 35, 1, 0, 1);

        $tiff = "II*\0" . pack('V', 8) . $ifd0 . $gps . $rationals;
        $app1 = "Exif\0\0" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /**
     * Whether the JPEG bytes carry any EXIF or GPS data.
     */
    public static function hasExif(string $jpeg): bool
    {
        return str_contains($jpeg, "Exif\0\0");
    }
}
