<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * One word OCR read, with its confidence (0–100) and where it is.
 */
final readonly class OcrWord
{
    public function __construct(
        public string $text,
        public float $confidence,
        public OcrBox $box,
        /** 1-based page of the file it came from. */
        public int $page = 1,
    ) {
    }
}
