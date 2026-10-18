<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * Why OCR gave no text (spec.md §7.27). Every one is a clean result for the
 * caller, never an exception reaching the user.
 */
enum OcrProblem: string
{
    /** No engine: Tesseract not found or `off`, no PaddleOCR URL, or `OCR_ENGINE=off`. */
    case Unavailable = 'unavailable';
    /** The engine ran past `OCR_TIMEOUT`. */
    case Timeout = 'timeout';
    /** The engine exited with an error, or answered with one. */
    case Failed = 'failed';
    /** The image is larger than OCR accepts (pixels), or a page couldn't be rendered. */
    case TooLarge = 'too_large';
    /** The file couldn't be opened as an image or a PDF. */
    case Unreadable = 'unreadable';
    /** Another AI request or scan of the same user's is still running. */
    case Busy = 'busy';
}
