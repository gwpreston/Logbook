<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;

/**
 * The scheduled backup files in BACKUP_PATH (spec.md §7.13, §7.30): only
 * `logbook-scheduled-YYYYMMDD-HHMMSS.zip` names, which retention may
 * delete and the Backup page lists and serves. The owner's own backups and
 * pre-restore backups are never touched.
 */
final readonly class ScheduledBackups
{
    public const string PATTERN = '/^logbook-scheduled-\d{8}-\d{6}\.zip$/';

    public function __construct(
        private AppSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    public function directory(): string
    {
        return rtrim($this->settings->backupPath, '/\\');
    }

    public function newName(): string
    {
        return 'logbook-scheduled-' . $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Ymd-His') . '.zip';
    }

    /**
     * @return list<array{name: string, size: int, created_at: DateTimeImmutable}> newest first
     */
    public function list(): array
    {
        $dir = $this->directory();
        $names = is_dir($dir) ? (scandir($dir) ?: []) : [];
        $files = [];
        foreach ($names as $name) {
            if (preg_match(self::PATTERN, $name) !== 1 || !is_file($dir . '/' . $name)) {
                continue;
            }
            $created = DateTimeImmutable::createFromFormat('Ymd-His', substr($name, 18, 15), new DateTimeZone('UTC'));
            $files[] = [
                'name' => $name,
                'size' => (int) filesize($dir . '/' . $name),
                'created_at' => $created === false ? new DateTimeImmutable('@' . (int) filemtime($dir . '/' . $name)) : $created,
            ];
        }
        // The names sort by time.
        usort($files, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $files;
    }

    /**
     * The path of a scheduled backup by name, or null for any other name.
     */
    public function path(string $name): ?string
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            return null;
        }
        $path = $this->directory() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * Delete all but the newest $keep scheduled files.
     *
     * @return list<string> the names deleted
     */
    public function prune(int $keep): array
    {
        $deleted = [];
        foreach (array_slice($this->list(), max(1, $keep)) as $file) {
            if (@unlink($this->directory() . '/' . $file['name'])) {
                $deleted[] = $file['name'];
            }
        }

        return $deleted;
    }
}
