<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

/**
 * Where the OCR tests' Tesseract stand-in is (spec.md §9, Phase 54.19, K212, #636): its logic is
 * `tests/Fixtures/ocr/fake-tesseract.php`, started by the `sh` launcher `fake-tesseract`, or on
 * Windows, which can't start an `sh` script, by `fake-tesseract.cmd`.
 */
final class FakeTesseract
{
    /**
     * The launcher for the OS running the tests. Built with the OS's own separator: cmd.exe could
     * take a `/` in the `.cmd`'s path for a switch.
     */
    public const string LAUNCHER = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Fixtures'
        . DIRECTORY_SEPARATOR . 'ocr' . DIRECTORY_SEPARATOR . 'fake-tesseract'
        . (PHP_OS_FAMILY === 'Windows' ? '.cmd' : '');
}
