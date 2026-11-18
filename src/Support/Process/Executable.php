<?php

declare(strict_types=1);

namespace Logbook\Support\Process;

/**
 * Finds an optional binary (spec.md §9: Ghostscript, Tesseract): a full
 * path is used as it is; a bare name is looked up on PATH. Null when it
 * isn't there, isn't executable, or is switched off ('').
 *
 * On Windows (spec.md §9, K213, #642) a path may use `\` or start with a
 * drive letter, and a bare name is also tried with each `PATHEXT`
 * extension, so `tesseract` finds `tesseract.exe`. Windows has no execute
 * bit and PHP's is_executable() there is false for a `.cmd`, so a file
 * counts as runnable when its extension is one `PATHEXT` names, as Windows
 * itself decides. Elsewhere nothing changes.
 */
final class Executable
{
    /** What Windows uses when `PATHEXT` is unset or empty. */
    private const string DEFAULT_PATHEXT = '.COM;.EXE;.BAT;.CMD';

    /**
     * @param string $os the OS family; a parameter so a test can run the Windows rules anywhere
     * @param string|null $pathExt Windows' `PATHEXT`; null reads the environment
     */
    public static function find(string $name, string $os = PHP_OS_FAMILY, ?string $pathExt = null): ?string
    {
        if ($name === '') {
            return null;
        }
        $windows = $os === 'Windows';
        $extensions = $windows ? self::extensions($pathExt ?? (string) getenv('PATHEXT')) : [];
        if (self::isPath($name, $windows)) {
            return self::runnable($name, $extensions) ? $name : null;
        }
        $names = [$name];
        foreach ($extensions as $extension) {
            $names[] = $name . $extension;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            // The real filesystem's separator: `\` keeps a Windows path one that cmd.exe reads
            // whole when it runs a `.cmd`, where a `/` could be taken for a switch.
            $dir = rtrim($dir, $windows ? '/\\' : '/') . DIRECTORY_SEPARATOR;
            foreach ($names as $candidateName) {
                $candidate = $dir . $candidateName;
                if (self::runnable($candidate, $extensions)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** A name holding a directory separator, or on Windows a drive letter ("C:..."), is a path. */
    private static function isPath(string $name, bool $windows): bool
    {
        if (str_contains($name, '/')) {
            return true;
        }

        return $windows && (str_contains($name, '\\') || preg_match('/^[A-Za-z]:/', $name) === 1);
    }

    /**
     * A file the OS will start: elsewhere one with an execute bit; on Windows ($extensions not
     * empty) one whose extension is in the list, compared ignoring case.
     *
     * @param list<string> $extensions
     */
    private static function runnable(string $file, array $extensions): bool
    {
        if (!is_file($file)) {
            return false;
        }
        if ($extensions === []) {
            return is_executable($file);
        }
        $extension = strrchr($file, '.');

        return $extension !== false && in_array(strtoupper($extension), $extensions, true);
    }

    /**
     * The extensions Windows runs without one being typed, upper-cased, from `PATHEXT`.
     *
     * @return non-empty-list<string>
     */
    private static function extensions(string $pathExt): array
    {
        $extensions = [];
        foreach (explode(';', $pathExt) as $extension) {
            $extension = strtoupper(trim($extension));
            if ($extension !== '') {
                $extensions[] = $extension;
            }
        }

        return $extensions !== [] ? $extensions : explode(';', self::DEFAULT_PATHEXT);
    }
}
