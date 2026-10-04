<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use ZipArchive;

/**
 * An app's backup archive once ArchiveReader has checked it: the CSV files
 * it holds (read into memory), the entries it ignores, and its photos,
 * extracted one at a time into a private temporary directory under names
 * Logbook chooses. close() removes the directory; so does dropping the
 * object.
 */
final class AppArchive
{
    private bool $closed = false;
    private int $extracted = 0;

    /**
     * @param list<array{name: string, contents: string}> $csv
     * @param list<string> $ignored entry names that are neither CSV nor the photo archive
     * @param array<string, array{index: int, size: int}> $photos by name, in the nested photo archive
     */
    public function __construct(
        public readonly array $csv,
        public readonly array $ignored,
        private readonly string $directory,
        private readonly ?ZipArchive $photoArchive,
        private readonly array $photos,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function hasPhoto(string $name): bool
    {
        return isset($this->photos[$name]);
    }

    /**
     * @return list<string>
     */
    public function photoNames(): array
    {
        return array_keys($this->photos);
    }

    /**
     * A photo copied to a temporary file (never at a path taken from its
     * name), or null when there is no photo of that name or it holds more
     * than its listed size.
     */
    public function extractPhoto(string $name): ?string
    {
        $entry = $this->photos[$name] ?? null;
        if ($entry === null || $this->photoArchive === null || $this->closed) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $target = sprintf(
            '%s/photo-%d.%s',
            $this->directory,
            ++$this->extracted,
            preg_match('/^[a-z]{3,4}$/', $extension) === 1 ? $extension : 'bin',
        );

        return ArchiveReader::copyEntry($this->photoArchive, $entry['index'], $entry['size'], $target) ? $target : null;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Close the photo archive and remove the temporary directory with
     * everything in it.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->photoArchive?->close();
        ArchiveReader::removeDirectory($this->directory);
    }
}
