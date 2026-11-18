<?php

declare(strict_types=1);

namespace Logbook\Support\Process;

/**
 * The platform's null device, for the standard streams of a binary Logbook starts (Tesseract,
 * Ghostscript) when they aren't read: `NUL` on Windows, `/dev/null` everywhere else. Windows has
 * no `/dev/null`, so opening it there stopped both tools from starting (spec.md §9, K213, #635).
 */
final class NullDevice
{
    /** The device's path; the OS family is a parameter so a test can ask for either answer. */
    public static function path(string $os = PHP_OS_FAMILY): string
    {
        return $os === 'Windows' ? 'NUL' : '/dev/null';
    }
}
