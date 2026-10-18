<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use InvalidArgumentException;

/**
 * `OCR_ENGINE` (spec.md §9, decided 2026-10-17, #494): which engine reads
 * text from images. `auto` uses PaddleOCR when `OCR_URL` is set, else
 * Tesseract.
 */
enum OcrEngineChoice: string
{
    case Auto = 'auto';
    case Tesseract = 'tesseract';
    case PaddleOcr = 'paddleocr';
    case Off = 'off';

    public static function fromEnv(string $value): self
    {
        $choice = self::tryFrom(strtolower(trim($value)));
        if ($choice === null) {
            throw new InvalidArgumentException(sprintf(
                'OCR_ENGINE "%s" must be one of auto, tesseract, paddleocr or off.',
                $value,
            ));
        }

        return $choice;
    }
}
