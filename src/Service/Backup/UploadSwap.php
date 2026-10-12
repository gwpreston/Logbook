<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use FilesystemIterator;
use Logbook\Support\Storage\FileStorage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * A restore's swap of the uploads directory (spec.md §7.13 *Restore*), in
 * steps that can be undone: apply() moves the current files aside and the
 * archive's (staged) in, inside the restore's transaction; undo() puts
 * them back if that transaction fails; finish() deletes the ones set aside
 * once it has committed. Every move is checked, so a file is never lost
 * quietly.
 */
final class UploadSwap
{
    private string $aside;
    /** @var list<string> current files moved aside, relative */
    private array $movedAside = [];
    /** @var list<string> archive files moved into place, relative */
    private array $placed = [];

    public function __construct(private readonly FileStorage $files, private readonly string $staging)
    {
        $this->aside = $files->root() . '/.replaced-' . bin2hex(random_bytes(8));
    }

    /**
     * Move the current files aside and the staged ones into place. On any
     * failure everything is put back and the failure thrown.
     */
    public function apply(): void
    {
        try {
            foreach ($this->files->all() as $relative) {
                self::move($this->files->absolutePath($relative), $this->aside . '/' . $relative);
                $this->movedAside[] = $relative;
            }
            if (is_dir($this->staging)) {
                foreach (FileStorage::storedFilesIn($this->staging) as $relative) {
                    self::move($this->staging . '/' . $relative, $this->files->absolutePath($relative));
                    $this->placed[] = $relative;
                }
            }
        } catch (Throwable $e) {
            $this->undo();
            throw $e;
        }
    }

    /**
     * Put the current files back where they were; the archive's go. Safe
     * to call more than once.
     *
     * @return list<string> files that could not be put back (the pre-restore backup has them)
     */
    public function undo(): array
    {
        foreach (array_reverse($this->placed) as $relative) {
            @unlink($this->files->absolutePath($relative));
        }
        $this->placed = [];
        $lost = [];
        foreach (array_reverse($this->movedAside) as $relative) {
            try {
                self::move($this->aside . '/' . $relative, $this->files->absolutePath($relative));
            } catch (RuntimeException) {
                $lost[] = $relative;
            }
        }
        $this->movedAside = [];
        if ($lost === []) {
            self::removeDirectory($this->aside);
        }
        self::removeDirectory($this->staging);

        return $lost;
    }

    /**
     * After the commit: the files set aside go, and so do scans waiting for
     * an entry, which belonged to the replaced accounts.
     */
    public function finish(): void
    {
        self::removeDirectory($this->aside);
        self::removeDirectory($this->staging);
        // Listed from the root: a scan is stored as "pending/<random>.ext".
        $root = $this->files->root();
        foreach (is_dir($root) ? FileStorage::storedFilesIn($root) : [] as $relative) {
            if (str_starts_with($relative, FileStorage::PENDING_DIRECTORY . '/')) {
                @unlink($root . '/' . $relative);
            }
        }
    }

    private static function move(string $from, string $to): void
    {
        $dir = dirname($to);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create "%s".', $dir));
        }
        if (!@rename($from, $to)) {
            throw new RuntimeException(sprintf('Cannot move "%s" to "%s".', $from, $to));
        }
    }

    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }
}
