<?php

declare(strict_types=1);

namespace Logbook\Support\Process;

/**
 * Finds an optional binary (spec.md §9: Ghostscript, Tesseract): a full
 * path is used as it is; a bare name is looked up on PATH. Null when it
 * isn't there, isn't executable, or is switched off ('').
 */
final class Executable
{
    public static function find(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        if (str_contains($name, '/')) {
            return is_file($name) && is_executable($name) ? $name : null;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $name;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
