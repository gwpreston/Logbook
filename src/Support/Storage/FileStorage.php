<?php

declare(strict_types=1);

namespace Logbook\Support\Storage;

use FilesystemIterator;
use InvalidArgumentException;
use Logbook\Support\Config\AppSettings;
use Psr\Http\Message\UploadedFileInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Uploaded files under UPLOAD_PATH, which lives outside the web root: files
 * are only ever reached through an authenticated Action, never by URL.
 *
 * Stored names are random, so user-supplied file names never touch the
 * filesystem. Relative paths are validated on every access.
 */
final readonly class FileStorage
{
    private const string RELATIVE_PATH = '#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*/[a-f0-9]{32}\.[a-z0-9]{1,5}$#';
    /**
     * Scanned files waiting for their entry (spec.md §7.27). They belong to
     * no entry, so backups leave them out and a restore leaves them alone.
     */
    public const string PENDING_DIRECTORY = 'pending';

    private string $root;

    public function __construct(AppSettings $settings)
    {
        $this->root = rtrim($settings->uploadPath, '/\\');
    }

    /**
     * Move an upload into $directory under a random name; returns the
     * relative path to store.
     */
    public function store(UploadedFileInterface $file, string $directory, string $extension): string
    {
        $relative = trim($directory, '/') . '/' . bin2hex(random_bytes(16)) . '.' . strtolower($extension);
        $absolute = $this->absolutePath($relative);

        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create upload directory "%s".', $dir));
        }

        $file->moveTo($absolute);

        return $relative;
    }

    /**
     * The upload directory (UPLOAD_PATH), without a trailing slash.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Whether $relative has the shape of a stored file's path ("dir/<random>.ext").
     */
    public static function isStoredPath(string $relative): bool
    {
        return preg_match(self::RELATIVE_PATH, $relative) === 1;
    }

    /**
     * Every stored file under the upload directory, as relative paths.
     * Anything else there (a restore's staging directory, stray files) is
     * left out, and so are pending scans.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $files = is_dir($this->root) ? self::storedFilesIn($this->root) : [];

        return array_values(array_filter(
            $files,
            static fn (string $relative): bool => !str_starts_with($relative, self::PENDING_DIRECTORY . '/'),
        ));
    }

    /**
     * A copy of a stored file in the system's temporary directory, for an
     * upload that must leave the original in place until a save commits.
     */
    public function temporaryCopy(string $relative, string $prefix = 'logbook-copy-'): string
    {
        $copy = tempnam(sys_get_temp_dir(), $prefix);
        if ($copy === false || !copy($this->absolutePath($relative), $copy)) {
            throw new RuntimeException(sprintf('Cannot copy "%s".', $relative));
        }

        return $copy;
    }

    /**
     * @return list<string> relative to $directory
     */
    public static function storedFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            if (self::isStoredPath($relative)) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    public function absolutePath(string $relative): string
    {
        if (!self::isStoredPath($relative)) {
            throw new InvalidArgumentException(sprintf('Invalid stored file path "%s".', $relative));
        }

        return $this->root . '/' . $relative;
    }

    public function exists(string $relative): bool
    {
        return is_file($this->absolutePath($relative));
    }

    /**
     * Delete a stored file; missing files are ignored.
     */
    public function delete(?string $relative): void
    {
        if ($relative === null) {
            return;
        }

        $path = $this->absolutePath($relative);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
