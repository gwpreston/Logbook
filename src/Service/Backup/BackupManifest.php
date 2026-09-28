<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * `manifest.json` of a backup archive (spec.md §7.13): what made it and
 * what it holds.
 */
final readonly class BackupManifest
{
    public const string FORMAT = 'logbook-backup';
    public const int FORMAT_VERSION = 1;

    /**
     * @param array<string, int> $tables table → row count
     */
    public function __construct(
        public string $appVersion,
        public string $schemaVersion,
        public DateTimeImmutable $createdAt,
        public string $driver,
        public array $tables,
        public int $files,
    ) {
    }

    public function rows(string $table): int
    {
        return $this->tables[$table] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'app_version' => $this->appVersion,
            'schema_version' => $this->schemaVersion,
            'created_at' => $this->createdAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            'driver' => $this->driver,
            'tables' => $this->tables,
            'files' => $this->files,
        ];
    }

    /**
     * @throws InvalidBackup when it is not a Logbook backup manifest
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidBackup('backup.error.not_a_backup');
        }
        if (($data['format_version'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidBackup('backup.error.format_version');
        }

        $tables = $data['tables'] ?? null;
        $counts = [];
        if (is_array($tables)) {
            foreach ($tables as $table => $count) {
                if (!is_string($table) || !is_int($count) || $count < 0) {
                    throw new InvalidBackup('backup.error.corrupt');
                }
                $counts[$table] = $count;
            }
        }

        $created = $data['created_at'] ?? null;
        try {
            $createdAt = is_string($created) ? new DateTimeImmutable($created) : null;
        } catch (Exception) {
            $createdAt = null;
        }

        $app = $data['app_version'] ?? null;
        $schema = $data['schema_version'] ?? null;
        $driver = $data['driver'] ?? null;
        $files = $data['files'] ?? null;
        if (
            !is_array($tables) || $createdAt === null || !is_string($app) || !is_string($schema)
            || !is_string($driver) || !is_int($files) || $files < 0
        ) {
            throw new InvalidBackup('backup.error.corrupt');
        }

        return new self($app, $schema, $createdAt->setTimezone(new DateTimeZone('UTC')), $driver, $counts, $files);
    }
}
