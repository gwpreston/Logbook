<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * An engine that reads text from one prepared page image (spec.md §7.27
 * *Reading text from images*; decided 2026-10-17, #489): Tesseract on this
 * server, or PaddleOCR reached over HTTP. Never throws: a failure is an
 * OcrResult with its problem.
 */
interface OcrEngine
{
    /** `tesseract` or `paddleocr`. */
    public function name(): string;

    /** Whether it is set up and can be tried, without running it. */
    public function isAvailable(): bool;

    /** Its version and languages, for Settings; runs or asks the engine. */
    public function status(): OcrEngineStatus;

    /**
     * Reads one page: $png is the prepared (greyscale, upright) image.
     *
     * @param list<string> $languages Tesseract codes wanted, most wanted first (`deu`, `eng`)
     */
    public function read(string $png, array $languages, PageLayout $layout, int $page = 1): OcrResult;
}
