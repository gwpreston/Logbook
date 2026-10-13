<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Logbook\Domain\Backup\BackupKind;
use Logbook\Domain\Backup\BackupRecord;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;

/**
 * The newest completed backup (spec.md §6 `backup.last`, §7.13 *How old is
 * the last backup?*): written by the download, the `backup` job and
 * `bin/backup.php create`, never by a pre-restore copy; kept across a
 * restore when newer (#400); seeded once from BACKUP_PATH (#401).
 */
final readonly class BackupRecorder
{
    public const string SETTING = 'backup.last';

    /** `bin/backup.php create`'s default name (BackupService::filename()), UTC. */
    private const string CLI_PATTERN = '/^logbook-backup-(\d{4}-\d{2}-\d{2}-\d{6})\.zip$/D';
    private const string SCHEDULED_PATTERN = '/^logbook-scheduled-(\d{8}-\d{6})\.zip$/D';

    public function __construct(
        private SettingRepository $settings,
        private AppSettings $app,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Record a backup that has just been completed.
     */
    public function record(BackupKind $kind, int $size): BackupRecord
    {
        $record = new BackupRecord($this->clock->now()->setTimezone(new DateTimeZone('UTC')), $kind, $size);
        $this->store($record);

        return $record;
    }

    /**
     * The newest backup, or null when there has never been one. The first
     * read with nothing stored seeds the record from BACKUP_PATH, and
     * stores "no backup" when the folder has none, so it is read once.
     */
    public function last(): ?BackupRecord
    {
        $value = $this->settings->find(self::SETTING)?->value;
        if (is_array($value)) {
            return self::decode($value);
        }
        $seed = $this->seed();
        $this->store($seed);

        return $seed;
    }

    /**
     * After a restore, which brought the archive's record: put back the
     * one from before it when that is newer (#400).
     */
    public function keepNewer(?BackupRecord $before): void
    {
        if ($before === null) {
            return;
        }
        $value = $this->settings->find(self::SETTING)?->value;
        $restored = is_array($value) ? self::decode($value) : null;
        if ($before->isNewerThan($restored)) {
            $this->store($before);
        }
    }

    /**
     * The newest scheduled or command-line backup in BACKUP_PATH, by the
     * UTC time in its name; pre-restore copies never count.
     */
    private function seed(): ?BackupRecord
    {
        $dir = rtrim($this->app->backupPath, '/\\');
        $names = is_dir($dir) ? (scandir($dir) ?: []) : [];
        $newest = null;
        foreach ($names as $name) {
            if (preg_match(self::SCHEDULED_PATTERN, $name, $m) === 1) {
                [$kind, $format] = [BackupKind::Scheduled, '!Ymd-His'];
            } elseif (preg_match(self::CLI_PATTERN, $name, $m) === 1) {
                [$kind, $format] = [BackupKind::Cli, '!Y-m-d-His'];
            } else {
                continue;
            }
            $at = DateTimeImmutable::createFromFormat($format, $m[1], new DateTimeZone('UTC'));
            if ($at === false || !is_file($dir . '/' . $name)) {
                continue;
            }
            $record = new BackupRecord($at, $kind, (int) filesize($dir . '/' . $name));
            if ($record->isNewerThan($newest)) {
                $newest = $record;
            }
        }

        return $newest;
    }

    private function store(?BackupRecord $record): void
    {
        $this->settings->save(self::SETTING, $record === null ? ['at' => null] : [
            'at' => $record->at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'kind' => $record->kind->value,
            'size' => $record->size,
        ]);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function decode(array $value): ?BackupRecord
    {
        $at = $value['at'] ?? null;
        $kind = is_string($value['kind'] ?? null) ? BackupKind::tryFrom($value['kind']) : null;
        if (!is_string($at) || $kind === null) {
            return null;
        }
        try {
            $time = new DateTimeImmutable($at);
        } catch (Exception) {
            return null;
        }
        $size = $value['size'] ?? 0;

        return new BackupRecord($time->setTimezone(new DateTimeZone('UTC')), $kind, is_int($size) && $size > 0 ? $size : 0);
    }
}
