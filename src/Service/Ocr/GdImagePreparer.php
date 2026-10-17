<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

use GdImage;
use Logbook\Support\Storage\ImageCleaner;

/**
 * OCR preparation with GD only, so no extension beyond the required ones
 * (spec.md §7.27): turned upright (EXIF), at most 4,000 px on the long edge,
 * greyscale, a percentile contrast stretch, deskewed by the angle that
 * maximises the variance of the rows' dark-pixel counts (±10° in 0.5°
 * steps), and enlarged when the text is under 20 px high so it is about
 * 30 px. The analysis runs on a copy about 1,000 px long, so it costs well
 * under a second; only the final rotate and scale touch the full image.
 */
final class GdImagePreparer implements ImagePreparer
{
    public const int MAX_EDGE = 4000;
    private const int ANALYSIS_EDGE = 1000;
    private const float MAX_ANGLE = 10.0;
    private const float ANGLE_STEP = 0.5;
    /** Share of pixels clipped at each end by the contrast stretch. */
    private const float CLIP = 0.01;
    private const int MIN_TEXT_HEIGHT = 20;
    private const int TARGET_TEXT_HEIGHT = 30;
    /** Most dark pixels the deskew search looks at. */
    private const int MAX_SAMPLES = 40000;

    public function prepare(string $bytes): ?PreparedImage
    {
        $image = self::open($bytes);
        if ($image === null) {
            return null;
        }
        $scale = 1.0;
        $image = self::fit($image, self::MAX_EDGE, $scale);
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        self::stretch($image);

        $small = self::thumbnail($image);
        $angle = self::skew($small);
        if ($angle !== 0.0) {
            $image = self::rotate($image, $angle);
            $small = self::rotate($small, $angle);
        }

        $height = self::textHeight($small) * (max(imagesx($image), imagesy($image)) / max(imagesx($small), imagesy($small)));
        if ($height > 0 && $height < self::MIN_TEXT_HEIGHT) {
            $image = self::enlarge($image, self::TARGET_TEXT_HEIGHT / $height, $scale);
        }

        return self::png($image, $angle, $scale, 'gd');
    }

    /**
     * Decoded, upright, refused when larger than ImageCleaner::MAX_PIXELS.
     */
    public static function open(string $bytes): ?GdImage
    {
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? $info['mime'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'logbook-ocr-');
        if ($path === false) {
            return null;
        }
        try {
            file_put_contents($path, $bytes);
            $image = ImageCleaner::openUpright($path, $mime);
        } finally {
            @unlink($path);
        }
        if ($image === null) {
            return null;
        }
        // Flatten any transparency onto white, as paper is.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        if ($flat === false) {
            return null;
        }
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $flat;
    }

    /**
     * Shrunk so its long edge is at most $edge.
     */
    public static function fit(GdImage $image, int $edge, float &$scale): GdImage
    {
        $long = max(imagesx($image), imagesy($image));
        if ($long <= $edge) {
            return $image;
        }
        $factor = $edge / $long;
        $scaled = self::resampled($image, $factor);
        if ($scaled === null) {
            return $image;
        }
        $scale *= $factor;

        return $scaled;
    }

    /**
     * Resized by $factor with imagecopyresampled, which (unlike
     * imagescale's bicubic mode) works for any size both ways.
     */
    private static function resampled(GdImage $image, float $factor): ?GdImage
    {
        $width = max(1, (int) round(imagesx($image) * $factor));
        $height = max(1, (int) round(imagesy($image) * $factor));
        $scaled = imagecreatetruecolor($width, $height);
        if ($scaled === false) {
            return null;
        }
        if (!imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image))) {
            return null;
        }

        return $scaled;
    }

    /**
     * The darkest and lightest 1% clipped and the rest spread over the full
     * range: a GD brightness shift then contrast, both done in C. Left alone
     * when it already spans nearly all of it, or is flat (a blank page).
     */
    public static function stretch(GdImage $image): void
    {
        [$low, $high] = self::percentiles(self::thumbnail($image));
        if ($high - $low < 8 || $high - $low >= 240) {
            return;
        }
        $factor = 255 / ($high - $low);
        // GD's contrast maps v to ((v/255 − 0.5)·k + 0.5)·255 with k = ((100 − c)/100)²,
        // so centring the range on 127.5 first gives (v − mid)·k + 127.5.
        imagefilter($image, IMG_FILTER_BRIGHTNESS, (int) round(127.5 - ($low + $high) / 2));
        imagefilter($image, IMG_FILTER_CONTRAST, (int) round(100 - 100 * sqrt($factor)));
    }

    /**
     * The grey levels below which 1% and 99% of the pixels lie.
     *
     * @return array{int, int}
     */
    public static function percentiles(GdImage $image): array
    {
        $histogram = array_fill(0, 256, 0);
        $width = imagesx($image);
        $height = imagesy($image);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $histogram[imagecolorat($image, $x, $y) & 0xFF]++;
            }
        }
        $total = $width * $height;
        $low = 0;
        $high = 255;
        $sum = 0;
        for ($v = 0; $v < 256; $v++) {
            $sum += $histogram[$v];
            if ($sum >= $total * self::CLIP) {
                $low = $v;
                break;
            }
        }
        $sum = 0;
        for ($v = 255; $v >= 0; $v--) {
            $sum += $histogram[$v];
            if ($sum >= $total * self::CLIP) {
                $high = $v;
                break;
            }
        }

        return [$low, $high];
    }

    /**
     * The angle (degrees, counter-clockwise positive as imagerotate takes
     * it) that straightens the text: the one whose rotated dark pixels give
     * the most uneven row counts, as text lines and the gaps between them
     * do when level. 0 when within half a step of level.
     */
    public static function skew(GdImage $image): float
    {
        $points = self::darkPixels($image);
        if (count($points) < 50) {
            return 0.0;
        }
        $best = 0.0;
        $bestScore = -1.0;
        for ($angle = -self::MAX_ANGLE; $angle <= self::MAX_ANGLE + 1e-9; $angle += self::ANGLE_STEP) {
            $radians = deg2rad($angle);
            $sin = sin($radians);
            $cos = cos($radians);
            $rows = [];
            foreach ($points as [$x, $y]) {
                // The row this pixel lands on once the image is turned by $angle counter-clockwise.
                $row = (int) round($y * $cos - $x * $sin);
                $rows[$row] = ($rows[$row] ?? 0) + 1;
            }
            $score = 0.0;
            foreach ($rows as $count) {
                $score += $count * $count;
            }
            if ($score > $bestScore + 1e-6 || (abs($score - $bestScore) <= 1e-6 && abs($angle) < abs($best))) {
                $bestScore = $score;
                $best = $angle;
            }
        }

        return abs($best) < self::ANGLE_STEP / 2 ? 0.0 : round($best, 1);
    }

    /**
     * The median height in pixels of the bands of rows that hold text (rows
     * with dark pixels, between rows without), or 0 when there are none.
     */
    public static function textHeight(GdImage $image): float
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $threshold = self::threshold($image);
        $bands = [];
        $run = 0;
        $minDark = max(1, (int) round($width * 0.005));
        for ($y = 0; $y < $height; $y++) {
            $dark = 0;
            for ($x = 0; $x < $width; $x++) {
                if ((imagecolorat($image, $x, $y) & 0xFF) < $threshold) {
                    $dark++;
                }
            }
            if ($dark >= $minDark) {
                $run++;
            } elseif ($run > 0) {
                $bands[] = $run;
                $run = 0;
            }
        }
        if ($run > 0) {
            $bands[] = $run;
        }
        // A band one or two rows high is a rule or noise, not text.
        $bands = array_values(array_filter($bands, static fn (int $b): bool => $b >= 3));
        if ($bands === []) {
            return 0.0;
        }
        sort($bands);

        return (float) $bands[intdiv(count($bands), 2)];
    }

    /**
     * Enlarged by $factor, keeping the long edge within MAX_EDGE.
     */
    public static function enlarge(GdImage $image, float $factor, float &$scale): GdImage
    {
        $long = max(imagesx($image), imagesy($image));
        $factor = min($factor, self::MAX_EDGE / $long);
        if ($factor <= 1.05) {
            return $image;
        }
        $scaled = self::resampled($image, $factor);
        if ($scaled === null) {
            return $image;
        }
        $scale *= $factor;

        return $scaled;
    }

    public static function rotate(GdImage $image, float $angle): GdImage
    {
        $rotated = imagerotate($image, $angle, (int) imagecolorallocate($image, 255, 255, 255));

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /**
     * A copy about ANALYSIS_EDGE long, for the histogram, skew and text
     * height.
     */
    public static function thumbnail(GdImage $image): GdImage
    {
        $scale = 1.0;

        return self::fit($image, self::ANALYSIS_EDGE, $scale);
    }

    public static function png(GdImage $image, float $angle, float $scale, string $preparer): ?PreparedImage
    {
        ob_start();
        $ok = imagepng($image, null, 6);
        $png = (string) ob_get_clean();

        return $ok && $png !== ''
            ? new PreparedImage($png, imagesx($image), imagesy($image), $angle, round($scale, 3), $preparer)
            : null;
    }

    /**
     * Dark pixels' coordinates, evenly thinned to at most MAX_SAMPLES as
     * they are collected (each time the list fills, every other one is
     * dropped and only every other later one is kept), so memory stays
     * small however much ink there is.
     *
     * @return list<array{int, int}>
     */
    private static function darkPixels(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $threshold = self::threshold($image);
        $points = [];
        $stride = 1;
        $seen = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ((imagecolorat($image, $x, $y) & 0xFF) >= $threshold || $seen++ % $stride !== 0) {
                    continue;
                }
                $points[] = [$x, $y];
                if (count($points) >= self::MAX_SAMPLES) {
                    $points = array_values(array_filter($points, static fn (int $i): bool => $i % 2 === 0, ARRAY_FILTER_USE_KEY));
                    $stride *= 2;
                }
            }
        }

        return $points;
    }

    /**
     * Ink versus paper: halfway between the image's 1st and 99th
     * percentile grey.
     */
    private static function threshold(GdImage $image): int
    {
        [$low, $high] = self::percentiles($image);

        return max(1, intdiv($low + $high, 2));
    }
}
