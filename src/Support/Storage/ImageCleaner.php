<?php

declare(strict_types=1);

namespace Logbook\Support\Storage;

use GdImage;

/**
 * Every photo upload is turned upright from its EXIF orientation and
 * re-encoded without its metadata (spec.md §7.12): GD writes no EXIF, XMP,
 * IPTC or text chunks, so a receipt photographed on the driveway no longer
 * records where the house is. Scans also get a downscaled JPEG copy to send
 * to a model (§7.27).
 */
final class ImageCleaner
{
    /** Larger images are refused: decoding them would not fit in memory. */
    public const int MAX_PIXELS = 50_000_000;

    private const int JPEG_QUALITY = 90;
    private const int WEBP_QUALITY = 90;
    /** Bytes per pixel GD needs for a true-colour image, with room to rotate. */
    private const int BYTES_PER_PIXEL = 9;

    private function __construct()
    {
    }

    /**
     * Rewrite the image at $path in place, upright and without metadata.
     * False when it cannot be decoded (the caller refuses the file).
     */
    public static function clean(string $path, string $mime): bool
    {
        $image = self::open($path, $mime);
        if ($image === null) {
            return false;
        }

        $written = match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, self::JPEG_QUALITY),
            'image/png' => imagepng($image, $path, 9),
            'image/webp' => imagewebp($image, $path, self::WEBP_QUALITY),
            default => false,
        };
        clearstatcache(true, $path);

        return $written;
    }

    /**
     * Whether the image decodes, leaving the file untouched (an incident
     * photo keeps its metadata, spec.md §7.12).
     */
    public static function decodes(string $path, string $mime): bool
    {
        return self::open($path, $mime) !== null;
    }

    /**
     * The image upright and without metadata, as bytes in its own format,
     * for a copy that leaves Logbook (an incident photo in the sale pack
     * ZIP, spec.md §7.19). Null when it cannot be decoded.
     */
    public static function cleanedBytes(string $path, string $mime): ?string
    {
        $image = self::open($path, $mime);
        if ($image === null) {
            return null;
        }

        ob_start();
        $written = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, self::JPEG_QUALITY),
            'image/png' => imagepng($image, null, 9),
            'image/webp' => imagewebp($image, null, self::WEBP_QUALITY),
            default => false,
        };
        $bytes = (string) ob_get_clean();

        return $written && $bytes !== '' ? $bytes : null;
    }

    /**
     * The image upright, scaled so its long edge is at most $maxEdge, as
     * JPEG bytes (transparency on white). Null when it cannot be decoded.
     */
    public static function downscaledJpeg(string $path, string $mime, int $maxEdge, int $quality = 85): ?string
    {
        $image = self::open($path, $mime);
        if ($image === null) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, $maxEdge / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        if ($canvas === false) {
            return null;
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        $ok = imagejpeg($canvas, null, $quality);
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? $bytes : null;
    }

    /**
     * The image upright, centre-cropped to a square and scaled to $edge
     * pixels, re-encoded as WebP (JPEG where GD lacks WebP), so nothing of
     * the original's metadata survives (an avatar, spec.md §7.9). Null when
     * it cannot be decoded.
     *
     * @return array{bytes: string, mime: string, extension: string}|null
     */
    public static function square(string $path, string $mime, int $edge): ?array
    {
        $edge = max(1, $edge);
        $image = self::open($path, $mime);
        if ($image === null) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $canvas = imagecreatetruecolor($edge, $edge);
        if ($canvas === false) {
            return null;
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            $edge,
            $edge,
            $side,
            $side,
        );

        $webp = function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0;
        ob_start();
        $ok = $webp ? imagewebp($canvas, null, self::WEBP_QUALITY) : imagejpeg($canvas, null, self::JPEG_QUALITY);
        $bytes = (string) ob_get_clean();
        if (!$ok || $bytes === '') {
            return null;
        }

        return $webp
            ? ['bytes' => $bytes, 'mime' => 'image/webp', 'extension' => 'webp']
            : ['bytes' => $bytes, 'mime' => 'image/jpeg', 'extension' => 'jpg'];
    }

    private static function open(string $path, string $mime): ?GdImage
    {
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }
        self::makeRoomFor($info[0] * $info[1]);

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$image instanceof GdImage) {
            return null;
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $mime === 'image/jpeg' ? self::upright($image, self::orientation($path)) : $image;
    }

    /**
     * The EXIF orientation (1–8) of a JPEG, 1 when there is none.
     */
    private static function orientation(string $path): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($path, 'IFD0');
        $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
    }

    private static function upright(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [5, 6, 7, 8], true)) {
            $rotated = imagerotate($image, $orientation <= 6 ? -90 : 90, 0);
            $image = $rotated instanceof GdImage ? $rotated : $image;
        } elseif ($orientation === 3 || $orientation === 4) {
            $rotated = imagerotate($image, 180, 0);
            $image = $rotated instanceof GdImage ? $rotated : $image;
        }
        // 4 is a vertical flip: the half-turn above, then a mirror.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private static function makeRoomFor(int $pixels): void
    {
        $needed = $pixels * self::BYTES_PER_PIXEL + memory_get_usage() + 32 * 1024 * 1024;
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit !== -1 && $limit < $needed) {
            @ini_set('memory_limit', (string) $needed);
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
