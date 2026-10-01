<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use FilesystemIterator;
use JsonException;
use Logbook\Domain\User\User;
use Logbook\Kernel;
use Logbook\Repository\BackupRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Storage\FileStorage;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Whole-dataset backup and restore (spec.md §7.13): every data table plus
 * every stored upload in one ZIP.
 *
 *   manifest.json            what made it and what it holds (BackupManifest)
 *   database/<table>.json    {"columns": [...], "rows": [[...], ...]}, values as strings or null
 *   uploads/<stored path>    photos and attachments, exactly as under UPLOAD_PATH
 *
 * Restore validates the whole archive before touching anything, writes a
 * safety backup of the current data to BACKUP_PATH, replaces the database
 * in one transaction and only then swaps the uploads.
 */
final readonly class BackupService
{
    /** Largest table file read back (uncompressed), against zip bombs. */
    private const int MAX_TABLE_BYTES = 128 * 1024 * 1024;

    public function __construct(
        private BackupRepository $repository,
        private FileStorage $files,
        private AppSettings $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Backups are ZIP files, which needs PHP's zip extension.
     */
    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * A download name like logbook-backup-2026-09-28-1530.zip (UTC).
     */
    public function filename(string $prefix = 'logbook-backup'): string
    {
        return sprintf('%s-%s.zip', $prefix, $this->clock->now()->format('Y-m-d-His'));
    }

    /**
     * Write a backup of everything to $path (overwritten if it exists).
     */
    public function create(string $path): BackupManifest
    {
        $tables = [];
        foreach (BackupRepository::TABLES as $table) {
            $tables[$table] = $this->repository->rows($table);
        }

        return $this->write($path, $tables, $this->files->all());
    }

    /**
     * Write one user's part of the data (spec.md §7.13 bin/export-user.php):
     * a backup of the same format and version holding only them, their
     * vehicles and their files.
     */
    public function createForUser(string $path, User $user): BackupManifest
    {
        $tables = [];
        foreach (BackupRepository::TABLES as $table) {
            $tables[$table] = $this->repository->rows($table);
        }
        $export = UserExport::of($tables, $user->id);

        return $this->write($path, $export['tables'], array_values(array_intersect($this->files->all(), $export['files'])));
    }

    /**
     * @param array<string, list<array<string, string|null>>> $tables every table in TABLES => its rows
     * @param list<string> $stored files under UPLOAD_PATH to include
     */
    private function write(string $path, array $tables, array $stored): BackupManifest
    {
        self::requireZip();

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(sprintf('Cannot write a backup to "%s".', $path));
        }

        $counts = [];
        foreach (BackupRepository::TABLES as $table) {
            $columns = $this->repository->columns($table);
            $rows = array_map(
                static fn (array $row): array => array_map(static fn (string $c): ?string => $row[$c] ?? null, $columns),
                $tables[$table] ?? [],
            );
            $counts[$table] = count($rows);
            $zip->addFromString(
                'database/' . $table . '.json',
                json_encode(
                    ['columns' => $columns, 'rows' => $rows],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            );
        }

        foreach ($stored as $relative) {
            $name = 'uploads/' . $relative;
            $zip->addFile($this->files->root() . '/' . $relative, $name);
            // Photos and PDFs are compressed already.
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }

        $manifest = new BackupManifest(
            Kernel::version(),
            $this->repository->schemaVersion(),
            $this->clock->now(),
            $this->settings->database->driver->value,
            $counts,
            count($stored),
        );
        $zip->addFromString(
            'manifest.json',
            json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        if (!$zip->close()) {
            throw new RuntimeException(sprintf('Cannot write a backup to "%s".', $path));
        }

        return $manifest;
    }

    /**
     * Check a backup completely without changing anything.
     *
     * @throws InvalidBackup
     */
    public function inspect(string $path): BackupManifest
    {
        $zip = $this->open($path);
        try {
            return $this->read($zip)[0];
        } finally {
            $zip->close();
        }
    }

    /**
     * Replace all data and uploads with the backup's.
     *
     * @return string the safety backup of what was there before
     * @throws InvalidBackup when the archive cannot be restored (nothing was changed)
     */
    public function restore(string $path): string
    {
        $zip = $this->open($path);
        $staging = null;
        try {
            [, $data, $uploads] = $this->read($zip);
            $safety = $this->safetyBackup();
            $staging = $this->files->root() . '/.restore-' . bin2hex(random_bytes(8));
            $this->extract($zip, $uploads, $staging);

            $this->repository->replaceAll($data);
        } catch (Throwable $e) {
            if ($staging !== null) {
                self::removeDirectory($staging);
            }
            throw $e;
        } finally {
            $zip->close();
        }

        $this->swapUploads($staging);
        $this->logger->notice('Restored a backup; the previous data was saved to {safety}.', ['safety' => $safety]);

        return $safety;
    }

    private function open(string $path): ZipArchive
    {
        self::requireZip();

        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidBackup('backup.error.not_a_zip');
        }

        return $zip;
    }

    /**
     * Validate everything in the archive and read its tables.
     *
     * @return array{0: BackupManifest, 1: array<string, list<array<string, string|null>>>, 2: list<string>}
     *               the manifest, table → rows (column → value), and the upload paths
     */
    private function read(ZipArchive $zip): array
    {
        $json = $zip->getFromName('manifest.json');
        if ($json === false) {
            throw new InvalidBackup('backup.error.not_a_backup');
        }
        $manifest = BackupManifest::fromJson($json);

        $current = $this->repository->schemaVersion();
        if ($manifest->schemaVersion !== $current) {
            throw new InvalidBackup('backup.error.schema', [
                'version' => $manifest->appVersion,
                'current' => Kernel::version(),
            ]);
        }

        $tables = array_keys($manifest->tables);
        $expected = BackupRepository::TABLES;
        sort($tables);
        sort($expected);
        if ($tables !== $expected) {
            throw new InvalidBackup('backup.error.corrupt');
        }

        $uploads = [];
        $tableFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new InvalidBackup('backup.error.corrupt');
            }
            $name = $stat['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }
            if ($name === 'manifest.json') {
                continue;
            }
            if (preg_match('#^database/([a-z_]+)\.json$#', $name, $m) === 1 && in_array($m[1], BackupRepository::TABLES, true)) {
                if ($stat['size'] > self::MAX_TABLE_BYTES) {
                    throw new InvalidBackup('backup.error.too_large');
                }
                $tableFiles[$m[1]] = $name;
                continue;
            }
            if (str_starts_with($name, 'uploads/') && FileStorage::isStoredPath(substr($name, 8))) {
                $uploads[] = substr($name, 8);
                continue;
            }
            throw new InvalidBackup('backup.error.unexpected_file', ['name' => mb_substr($name, 0, 120)]);
        }
        if (count($uploads) !== $manifest->files) {
            throw new InvalidBackup('backup.error.corrupt');
        }

        $data = [];
        foreach (BackupRepository::TABLES as $table) {
            $file = $tableFiles[$table] ?? null;
            $contents = $file === null ? false : $zip->getFromName($file);
            if ($contents === false) {
                throw new InvalidBackup('backup.error.corrupt');
            }
            $data[$table] = $this->rows($table, $contents, $manifest->rows($table));
        }

        return [$manifest, $data, $uploads];
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function rows(string $table, string $contents, int $expectedCount): array
    {
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidBackup('backup.error.corrupt');
        }
        $columns = is_array($decoded) ? ($decoded['columns'] ?? null) : null;
        $rows = is_array($decoded) ? ($decoded['rows'] ?? null) : null;
        if (!is_array($columns) || !is_array($rows) || !array_is_list($rows) || count($rows) !== $expectedCount) {
            throw new InvalidBackup('backup.error.corrupt');
        }

        // Only this schema's columns, each once, and always the id.
        $known = $this->repository->columns($table);
        foreach ($columns as $column) {
            if (!is_string($column) || !in_array($column, $known, true)) {
                throw new InvalidBackup('backup.error.corrupt');
            }
        }
        if (count(array_unique($columns)) !== count($columns) || !in_array('id', $columns, true)) {
            throw new InvalidBackup('backup.error.corrupt');
        }

        /** @var list<string> $columns */
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== count($columns)) {
                throw new InvalidBackup('backup.error.corrupt');
            }
            foreach ($row as $value) {
                if ($value !== null && !is_string($value)) {
                    throw new InvalidBackup('backup.error.corrupt');
                }
            }
            /** @var list<string|null> $row */
            $result[] = array_combine($columns, $row);
        }

        return $result;
    }

    /**
     * Back up the current data before it is replaced.
     */
    private function safetyBackup(): string
    {
        $dir = rtrim($this->settings->backupPath, '/\\');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create the backup directory "%s" (BACKUP_PATH).', $dir));
        }
        $path = $dir . '/' . $this->filename('pre-restore');
        $this->create($path);

        return $path;
    }

    /**
     * @param list<string> $uploads
     */
    private function extract(ZipArchive $zip, array $uploads, string $staging): void
    {
        foreach ($uploads as $relative) {
            $target = $staging . '/' . $relative;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Cannot create "%s".', $dir));
            }
            $in = $zip->getStream('uploads/' . $relative);
            $out = fopen($target, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException(sprintf('Cannot extract "%s" from the backup.', $relative));
            }
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
                fclose($out);
            }
        }
    }

    /**
     * The database now matches the backup: replace the stored files with
     * the backup's (staged in the same directory, so each move is a rename).
     */
    private function swapUploads(string $staging): void
    {
        foreach ($this->files->all() as $relative) {
            $this->files->delete($relative);
        }
        // Scans waiting for an entry belonged to the replaced accounts (their rows go too).
        $pending = $this->files->root() . '/' . FileStorage::PENDING_DIRECTORY;
        if (is_dir($pending)) {
            foreach (FileStorage::storedFilesIn($pending) as $relative) {
                @unlink($pending . '/' . $relative);
            }
        }

        if (is_dir($staging)) {
            foreach (FileStorage::storedFilesIn($staging) as $relative) {
                $target = $this->files->absolutePath($relative);
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new RuntimeException(sprintf('Cannot create "%s".', $dir));
                }
                rename($staging . '/' . $relative, $target);
            }
            self::removeDirectory($staging);
        }
    }

    private static function removeDirectory(string $dir): void
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

    private static function requireZip(): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('Backups need PHP\'s zip extension.');
        }
    }
}
