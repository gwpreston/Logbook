<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

use Throwable;

/**
 * OCR preparation with Imagick, when the extension is loaded (decided
 * 2026-10-17, #492): the same steps as GdImagePreparer with ImageMagick's
 * own orient, greyscale, contrast stretch and deskew, which are faster and
 * finer. The contrast stretch and text height are measured as GD does
 * them, on a small copy. Any
 * failure gives null, and the caller falls back to GD.
 */
final class ImagickImagePreparer implements ImagePreparer
{
    /** ImageMagick's deskew threshold, as a share of the quantum range. */
    private const float DESKEW_THRESHOLD = 0.4;
    /** Degrees below which a page is left as it is, as GD's half-step. */
    private const float MIN_ANGLE = 0.25;
    /** Bytes of ImageMagick pixel cache kept in memory. */
    private const int MEMORY_LIMIT = 128 * 1024 * 1024;

    public function isAvailable(): bool
    {
        return class_exists(\Imagick::class);
    }

    public function prepare(string $bytes): ?PreparedImage
    {
        if (!$this->isAvailable() || !self::acceptable($bytes)) {
            return null;
        }
        try {
            self::limitResources();
            $image = new \Imagick();
            // An animated WebP or multi-frame file would decode every frame: GD takes those.
            $image->pingImageBlob($bytes);
            if ($image->getNumberImages() !== 1) {
                return null;
            }
            $image->clear();
            // No jpeg:size hint: ImageMagick 7 takes it as a size to reach, and doubled
            // every JPEG under 4,000 px (merge review, 2026-10-17).
            $image->readImageBlob($bytes);
            $image->autoOrient();
            $image->setImageBackgroundColor('white');
            $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $scale = 1.0;
            $long = max($image->getImageWidth(), $image->getImageHeight());
            if ($long > GdImagePreparer::MAX_EDGE) {
                $scale = GdImagePreparer::MAX_EDGE / $long;
                self::resize($image, $scale);
            }
            // Greyscale in sRGB (IM7's GRAY colorspace is linear, which pales the print).
            $image->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
            $flat = $this->stretch($image);

            // Measured on a copy, and kept only when worth a resample. A flat
            // (blank) page has no lines to level: ImageMagick would still turn it.
            $deskewed = $flat ? null : clone $image;
            $deskewed?->deskewImage(\Imagick::getQuantum() * self::DESKEW_THRESHOLD);
            $artifact = $deskewed?->getImageArtifact('deskew:angle');
            $angle = is_numeric($artifact) ? round(-(float) $artifact, 1) : 0.0;
            if ($deskewed !== null && abs($angle) >= self::MIN_ANGLE) {
                $image->clear();
                $image = $deskewed;
                $image->setImagePage(0, 0, 0, 0);
            } else {
                $deskewed?->clear();
                $angle = 0.0;
            }

            $height = $this->textHeight($image);
            if ($height > 0 && $height < 20) {
                $factor = min(30 / $height, GdImagePreparer::MAX_EDGE / max($image->getImageWidth(), $image->getImageHeight()));
                if ($factor > 1.05) {
                    self::resize($image, $factor);
                    $scale *= $factor;
                }
            }
            // 8-bit greyscale: left to itself, ImageMagick writes a near
            // black-and-white image as a dithered 1-bit PNG.
            $image->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
            $image->setImageDepth(8);
            $image->setImageFormat('png');
            $image->setOption('png:color-type', '0');
            $image->setOption('png:bit-depth', '8');
            $image->stripImage();
            $png = $image->getImageBlob();
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $prepared = new PreparedImage($png, $width, $height, $angle, round($scale, 3), 'imagick');
            $image->clear();

            return $png === '' ? null : $prepared;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * ImageMagick's own memory is outside PHP's memory_limit, so cap it:
     * past these it pages to disk (itself capped) or fails, and GD is used.
     */
    private static function limitResources(): void
    {
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, self::MEMORY_LIMIT);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, self::MEMORY_LIMIT);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, 1024 * 1024 * 1024);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, \Logbook\Support\Storage\ImageCleaner::MAX_PIXELS);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 1);
    }

    /**
     * Only images GD would also take, and no larger (ImageMagick reads
     * many more formats; a scan is a photo or a rendered PDF page).
     */
    private static function acceptable(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);

        return is_array($info)
            && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            && $info[0] * $info[1] <= \Logbook\Support\Storage\ImageCleaner::MAX_PIXELS;
    }

    /**
     * GD's percentile stretch (0.1% / 99%, left alone at good contrast),
     * measured on a small copy and applied with levelImage. ImageMagick's
     * own normalize and contrastStretchImage both blanked or crushed test
     * pages for Tesseract. True when the page is flat (nothing on it).
     */
    private function stretch(\Imagick $image): bool
    {
        $small = self::small($image);
        if ($small === null) {
            return false;
        }
        [$low, $high] = GdImagePreparer::percentiles($small);
        if ($high - $low < 8) {
            return true;
        }
        if ($high - $low < GdImagePreparer::GOOD_CONTRAST) {
            $quantum = \Imagick::getQuantum() / 255;
            $image->levelImage($low * $quantum, 1.0, $high * $quantum);
        }

        return false;
    }

    private function textHeight(\Imagick $image): float
    {
        $small = self::small($image);
        if ($small === null) {
            return 0.0;
        }
        $ratio = max($image->getImageWidth(), $image->getImageHeight()) / max(imagesx($small), imagesy($small));

        return GdImagePreparer::textHeight($small) * $ratio;
    }

    /**
     * A GD copy about 1,000 px long, for measuring.
     */
    private static function small(\Imagick $image): ?\GdImage
    {
        $copy = clone $image;
        $copy->thumbnailImage(1000, 1000, true);
        $copy->setImageFormat('png');
        $small = @imagecreatefromstring($copy->getImageBlob());
        $copy->clear();
        if ($small === false) {
            return null;
        }
        if (!imageistruecolor($small)) {
            imagepalettetotruecolor($small);
        }

        return $small;
    }

    private static function resize(\Imagick $image, float $factor): void
    {
        $width = (int) round($image->getImageWidth() * $factor);
        $height = (int) round($image->getImageHeight() * $factor);
        $image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
    }
}
