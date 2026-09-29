<?php

declare(strict_types=1);

namespace Logbook\Support\Zip;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One file in a StoredZipStream: a file on disk or a string, under a name.
 */
final readonly class ZipEntry
{
    private function __construct(
        /** The name in the archive (UTF-8, "/" separated). */
        public string $name,
        /** Absolute path of the file, or null for $contents. */
        public ?string $path,
        public ?string $contents,
        /** Shown as the file's modification time (read as local wall-clock time). */
        public DateTimeImmutable $modified,
    ) {
        $unsafe = $name === '' || str_starts_with($name, '/') || str_contains($name, '\\');
        if ($unsafe || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            throw new InvalidArgumentException(sprintf('Unsafe name in a ZIP: "%s".', $name));
        }
    }

    public static function file(string $name, string $path, DateTimeImmutable $modified): self
    {
        return new self($name, $path, null, $modified);
    }

    public static function text(string $name, string $contents, DateTimeImmutable $modified): self
    {
        return new self($name, null, $contents, $modified);
    }
}
