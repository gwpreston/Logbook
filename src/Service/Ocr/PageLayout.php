<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * How text sits on a page, which picks Tesseract's page segmentation mode
 * (spec.md §7.27): a till receipt is one column of lines of varying size;
 * an A4 page is left to automatic segmentation.
 */
enum PageLayout: string
{
    case Receipt = 'receipt';
    case Page = 'page';

    /** Taller than 2:1 is a receipt. */
    public static function of(int $width, int $height): self
    {
        return $width > 0 && $height > 2 * $width ? self::Receipt : self::Page;
    }

    public function tesseractMode(): int
    {
        return match ($this) {
            self::Receipt => 4,
            self::Page => 3,
        };
    }
}
