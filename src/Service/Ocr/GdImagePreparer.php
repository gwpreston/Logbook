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
 * steps), and enlarged when the text is under 15 px high so it is about
 * 30 px (K85: from 20 px, enlarging read printed 0s as 6s). The analysis runs on a copy about 1,000 px long, so it costs well
 * under a second; only the final rotate and scale touch the full image.
 */
final class GdImagePreparer implements ImagePreparer
{
    public const int MAX_EDGE = 4000;
    private const int ANALYSIS_EDGE = 1000;
    private const float MAX_ANGLE = 10.0;
    private const float ANGLE_STEP = 0.5;
    /**
     * Share of pixels clipped at the dark end by the contrast stretch: small,
     * because on a sparse page (a short letter on A4) under 1% of the pixels
     * are ink, and clipping 1% there would turn the paper's grey edges black.
     */
    private const float CLIP_DARK = 0.001;
    /** Share clipped at the light (paper) end. */
    private const float CLIP_LIGHT = 0.01;
    /** Ink and paper this many grey levels apart need no stretch: it only blurs a clean print. */
    public const int GOOD_CONTRAST = 200;
    /** Memory a GD truecolor pixel takes, with some slack. */
    private const int BYTES_PER_COPY_PIXEL = 5;
    /** K85: the eval found enlarging text of 15–20 px lowered accuracy on every kind. */
    public const int MIN_TEXT_HEIGHT = 15;
    private const int TARGET_TEXT_HEIGHT = 30;
    /** Largest page written as an 8-bit palette PNG (4000×3000, a phone photo fitted). */
    private const int PALETTE_MAX_PIXELS = 12_000_000;
    /** Most dark pixels the deskew search looks at. */
    private const int MAX_SAMPLES = 40000;

    /**
     * Text under $minTextHeight px is enlarged to about $targetTextHeight px
     * (0 never enlarges); `bin/ai-eval.php --ocr --enlarge` tries others (K85).
     */
    public function __construct(
        private readonly int $minTextHeight = self::MIN_TEXT_HEIGHT,
        private readonly int $targetTextHeight = self::TARGET_TEXT_HEIGHT,
    ) {
    }

    public function prepare(string $bytes): ?PreparedImage
    {
        $scale = 1.0;
        $image = self::open($bytes, $scale);
        if ($image === null) {
            return null;
        }
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        self::stretch($image);

        $small = self::thumbnail($image);
        $angle = self::skew($small);
        if ($angle !== 0.0) {
            // Shrunk first if the rotated page would pass MAX_EDGE (K75).
            $image = self::rotate(self::fitRotated($image, $angle, $scale), $angle);
            $small = self::rotate($small, $angle);
        }

        $height = self::textHeight($small) * (max(imagesx($image), imagesy($image)) / max(imagesx($small), imagesy($small)));
        if ($height > 0 && $height < $this->minTextHeight) {
            $image = self::enlarge($image, $this->targetTextHeight / $height, $scale);
        }

        return self::png(self::palette($image), $angle, $scale, 'gd');
    }

    /**
     * Decoded, upright, at most MAX_EDGE on its long edge and on white paper
     * (any transparency flattened), or null when it can't be read or is
     * larger than ImageCleaner::MAX_PIXELS. The full-size decode is let go
     * as soon as it is fitted, and memory is then reserved for the working
     * copies at the fitted size only.
     */
    public static function open(string $bytes, float &$scale = 1.0): ?GdImage
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
        $long = max(imagesx($image), imagesy($image));
        $factor = min(1.0, self::MAX_EDGE / $long);
        if ($factor === 1.0 && $mime === 'image/jpeg') {
            $paper = $image;
        } else {
            // One copy, fitted and put on white paper together.
            $width = max(1, (int) round(imagesx($image) * $factor));
            $height = max(1, (int) round(imagesy($image) * $factor));
            ImageCleaner::makeRoomFor($width * $height);
            $paper = imagecreatetruecolor($width, $height);
            if ($paper === false) {
                return null;
            }
            imagefill($paper, 0, 0, (int) imagecolorallocate($paper, 255, 255, 255));
            imagealphablending($paper, true);
            imagecopyresampled($paper, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            $scale *= $factor;
        }
        unset($image);
        // At most two fitted copies alive at once (the page and its rotated or
        // rescaled copy), at GD's 4 bytes a pixel plus slack; the analysis copy is small.
        ImageCleaner::makeRoomFor(imagesx($paper) * imagesy($paper), 2, self::BYTES_PER_COPY_PIXEL);

        return $paper;
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
     * The darkest 0.1% and lightest 1% clipped and the rest spread over the full
     * range: a GD brightness shift then contrast, both done in C. Left alone
     * when ink and paper are already far apart, or it is flat (a blank page).
     */
    public static function stretch(GdImage $image): void
    {
        [$low, $high] = self::percentiles(self::thumbnail($image));
        if ($high - $low < 8 || $high - $low >= self::GOOD_CONTRAST) {
            return;
        }
        $factor = 255 / ($high - $low);
        // GD's contrast maps v to ((v/255 − 0.5)·k + 0.5)·255 with k = ((100 − c)/100)²,
        // so centring the range on 127.5 first gives (v − mid)·k + 127.5.
        imagefilter($image, IMG_FILTER_BRIGHTNESS, (int) round(127.5 - ($low + $high) / 2));
        imagefilter($image, IMG_FILTER_CONTRAST, (int) round(100 - 100 * sqrt($factor)));
    }

    /**
     * The grey levels below which 0.1% and 99% of the pixels lie.
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
            if ($sum >= $total * self::CLIP_DARK) {
                $low = $v;
                break;
            }
        }
        $sum = 0;
        for ($v = 255; $v >= 0; $v--) {
            $sum += $histogram[$v];
            if ($sum >= $total * self::CLIP_LIGHT) {
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

    /**
     * Shrunk so that, once rotated by $angle, its long edge is at most
     * MAX_EDGE: rotating widens the page by up to a fifth at 10°.
     */
    public static function fitRotated(GdImage $image, float $angle, float &$scale): GdImage
    {
        $radians = deg2rad(abs($angle));
        $width = imagesx($image);
        $height = imagesy($image);
        $long = max($width * cos($radians) + $height * sin($radians), $height * cos($radians) + $width * sin($radians));
        if ($long <= self::MAX_EDGE) {
            return $image;
        }
        $factor = self::MAX_EDGE / $long;
        $scaled = self::resampled($image, $factor);
        if ($scaled === null) {
            return $image;
        }
        $scale *= $factor;

        return $scaled;
    }

    /**
     * The grey page as a palette image, so its PNG is 8-bit: under half the
     * size of a truecolor one and quicker to write (K82). The palette is
     * matched back to the page's own greys, within two levels. It needs a
     * full-size copy beside the page, and GD's quantiser needs more again,
     * so room is made for two more copies first (`open()` reserved two in
     * all), and a page over PALETTE_MAX_PIXELS stays truecolor: a 4000×4000
     * photo ran out of a 256M limit here (K86).
     */
    private static function palette(GdImage $image): GdImage
    {
        $pixels = imagesx($image) * imagesy($image);
        if ($pixels > self::PALETTE_MAX_PIXELS) {
            return $image;
        }
        ImageCleaner::makeRoomFor($pixels, 2, self::BYTES_PER_COPY_PIXEL);
        $copy = imagecreatetruecolor(imagesx($image), imagesy($image));
        if ($copy === false || !imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image))) {
            return $image;
        }
        if (!imagetruecolortopalette($copy, false, 256)) {
            return $image;
        }
        imagecolormatch($image, $copy);

        return $copy;
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
     * Ink versus paper: halfway between the image's darkest 0.1% and its
     * lightest 1%.
     */
    private static function threshold(GdImage $image): int
    {
        [$low, $high] = self::percentiles($image);

        return max(1, intdiv($low + $high, 2));
    }
}
