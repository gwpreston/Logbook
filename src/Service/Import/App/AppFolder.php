<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

/**
 * A folder of an app's files on the command line (Tracktor's uploads,
 * spec.md §7.13): the regular files directly inside it, by base name. No
 * subfolder or link is followed. Each file is copied into a private
 * temporary directory before it is used, so the owner's folder is only
 * ever read.
 */
final class AppFolder implements AppFiles
{
    private bool $closed = false;
    private int $extracted = 0;

    /**
     * @param array<string, string> $files base name → path
     */
    public function __construct(private readonly array $files, private readonly string $directory)
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function has(string $name): bool
    {
        return isset($this->files[$name]);
    }

    public function extract(string $name): ?string
    {
        $source = $this->files[$name] ?? null;
        if ($source === null || $this->closed || !is_file($source) || is_link($source)) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $target = sprintf(
            '%s/file-%d.%s',
            $this->directory,
            ++$this->extracted,
            preg_match('/^[a-z]{3,4}$/', $extension) === 1 ? $extension : 'bin',
        );

        return @copy($source, $target) ? $target : null;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ArchiveReader::removeDirectory($this->directory);
    }
}
