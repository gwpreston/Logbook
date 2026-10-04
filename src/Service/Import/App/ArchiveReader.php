<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use Throwable;
use ZipArchive;

/**
 * Opens an app's backup ZIP safely (spec.md §7.13 *ZIP safety*, Phase 31).
 * Every entry is checked before anything is read: their number, their
 * names (no absolute paths, `..`, backslashes or drive letters), their
 * sizes against the archive's own size and a hard cap, and their
 * compression ratio. One nested archive is allowed, by its exact name
 * (Fuelio's `pictures.data`), opened one level deep under the same rules
 * and holding images only. Any other archive inside refuses the file.
 *
 * Nothing is ever written at a path taken from an entry's name: the nested
 * archive and each photo are copied into a private temporary directory
 * under names chosen here, their size enforced while copying.
 */
final readonly class ArchiveReader
{
    public const int MAX_ENTRIES = 50;
    public const int MAX_PHOTOS = 5000;
    public const string NESTED = 'pictures.data';
    /** Uncompressed bytes in all, against the archive's own size. */
    public const int SIZE_FACTOR = 10;
    public const int MAX_TOTAL_BYTES = 2 * 1024 * 1024 * 1024;
    /** An entry may compress at most this well... */
    public const int MAX_RATIO = 100;
    /** ... once it is bigger than this (a small text file can compress better). */
    public const int RATIO_FLOOR_BYTES = 1024 * 1024;

    private const array PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'gif'];
    private const array ARCHIVE_EXTENSIONS = ['zip', 'jar', '7z', 'rar', 'gz', 'tgz', 'tar', 'bz2', 'xz', 'apk', 'data'];

    public function __construct(private string $temporaryRoot)
    {
    }

    /**
     * @throws ArchiveRefused when anything about the archive is outside the rules
     */
    public function open(string $path): AppArchive
    {
        $size = is_file($path) ? (int) filesize($path) : 0;
        $zip = self::openZip($path);
        $directory = null;
        $photoArchive = null;
        try {
            $csv = [];
            $ignored = [];
            $nested = null;
            foreach (self::entries($zip, self::MAX_ENTRIES, $size) as $entry) {
                if ($entry['directory']) {
                    continue;
                }
                $base = basename($entry['name']);
                if ($base === self::NESTED && $nested === null) {
                    $nested = $entry;
                    continue;
                }
                if (self::looksLikeArchive($zip, $entry)) {
                    throw new ArchiveRefused('import_app.archive.nested');
                }
                if (strtolower(pathinfo($base, PATHINFO_EXTENSION)) === 'csv') {
                    $contents = $zip->getFromIndex($entry['index'], $entry['size'] + 1);
                    if (!is_string($contents) || strlen($contents) !== $entry['size']) {
                        throw new ArchiveRefused('import_app.archive.unreadable');
                    }
                    $csv[] = ['name' => $base, 'contents' => $contents];
                    continue;
                }
                $ignored[] = $entry['name'];
            }

            $directory = $this->makeDirectory();
            $photos = [];
            if ($nested !== null) {
                $copy = $directory . '/photos.zip';
                if (!self::copyEntry($zip, $nested['index'], $nested['size'], $copy)) {
                    throw new ArchiveRefused('import_app.archive.unreadable');
                }
                $photoArchive = self::openZip($copy);
                foreach (self::entries($photoArchive, self::MAX_PHOTOS, $nested['size']) as $entry) {
                    if ($entry['directory']) {
                        continue;
                    }
                    $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
                    if (!in_array($extension, self::PHOTO_EXTENSIONS, true) || self::looksLikeArchive($photoArchive, $entry)) {
                        throw new ArchiveRefused('import_app.archive.nested');
                    }
                    $photos[basename($entry['name'])] = ['index' => $entry['index'], 'size' => $entry['size']];
                }
            }
            $zip->close();

            return new AppArchive($csv, $ignored, $directory, $photoArchive, $photos);
        } catch (Throwable $e) {
            $zip->close();
            $photoArchive?->close();
            if ($directory !== null) {
                self::removeDirectory($directory);
            }
            throw $e;
        }
    }

    /**
     * Copy one entry to a new file at $target, stopping (and deleting the
     * copy) as soon as it grows past its listed size: a lying header can't
     * fill the disk.
     */
    public static function copyEntry(ZipArchive $zip, int $index, int $size, string $target): bool
    {
        $in = $zip->getStreamIndex($index);
        if ($in === false) {
            return false;
        }
        $out = @fopen($target, 'xb');
        if ($out === false) {
            fclose($in);

            return false;
        }
        $written = 0;
        $ok = true;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            $written += strlen($chunk);
            if ($written > $size) {
                $ok = false;
                break;
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        if (!$ok || $written !== $size) {
            @unlink($target);

            return false;
        }

        return true;
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private static function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new ArchiveRefused('import_app.archive.not_zip');
        }

        return $zip;
    }

    /**
     * Every entry, checked: their count, names, sizes and ratios.
     *
     * @return list<array{index: int, name: string, size: int, compressed: int, directory: bool}>
     */
    private static function entries(ZipArchive $zip, int $maxEntries, int $archiveBytes): array
    {
        if ($zip->numFiles > $maxEntries) {
            throw new ArchiveRefused('import_app.archive.too_many', ['max' => $maxEntries]);
        }
        $limit = min(self::MAX_TOTAL_BYTES, max(1, $archiveBytes) * self::SIZE_FACTOR);
        $total = 0;
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new ArchiveRefused('import_app.archive.unreadable');
            }
            $name = (string) $stat['name'];
            if (!self::isSafeName($name)) {
                throw new ArchiveRefused('import_app.archive.path', ['name' => mb_substr(self::printable($name), 0, 80)]);
            }
            $size = (int) $stat['size'];
            $compressed = (int) $stat['comp_size'];
            $total += $size;
            if ($total > $limit || ($size > self::RATIO_FLOOR_BYTES && $size > max(1, $compressed) * self::MAX_RATIO)) {
                throw new ArchiveRefused('import_app.archive.too_big');
            }
            $entries[] = [
                'index' => $i,
                'name' => $name,
                'size' => $size,
                'compressed' => $compressed,
                'directory' => str_ends_with($name, '/'),
            ];
        }

        return $entries;
    }

    /**
     * A relative path with no way out of the folder it would sit in.
     */
    private static function isSafeName(string $name): bool
    {
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\\\\]/', $name) === 1) {
            return false;
        }
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        return !in_array('..', explode('/', $name), true);
    }

    private static function printable(string $name): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', $name);
    }

    /**
     * An archive inside the archive, by its name or its first bytes.
     *
     * @param array{index: int, name: string, size: int, compressed: int, directory: bool} $entry
     */
    private static function looksLikeArchive(ZipArchive $zip, array $entry): bool
    {
        if (in_array(strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)), self::ARCHIVE_EXTENSIONS, true)) {
            return true;
        }
        $head = $zip->getFromIndex($entry['index'], 4);

        return is_string($head) && (
            str_starts_with($head, "PK\x03\x04")
            || str_starts_with($head, "\x1F\x8B")
            || str_starts_with($head, "7z\xBC\xAF")
            || str_starts_with($head, 'Rar!')
        );
    }

    private function makeDirectory(): string
    {
        if (!is_dir($this->temporaryRoot) && !@mkdir($this->temporaryRoot, 0700, true) && !is_dir($this->temporaryRoot)) {
            throw new ArchiveRefused('import_app.archive.unreadable');
        }
        $directory = rtrim($this->temporaryRoot, '/') . '/app-import-' . bin2hex(random_bytes(12));
        if (!@mkdir($directory, 0700)) {
            throw new ArchiveRefused('import_app.archive.unreadable');
        }

        return $directory;
    }
}
