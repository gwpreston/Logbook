<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

/**
 * *Scheduled backups* (spec.md §7.30): off, daily or weekly, keeping the
 * last N scheduled files.
 */
enum BackupSchedule: string
{
    case Off = 'off';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public const int KEEP_DEFAULT = 7;
    public const int KEEP_MIN = 1;
    public const int KEEP_MAX = 60;

    public function interval(): ?int
    {
        return match ($this) {
            self::Off => null,
            self::Daily => 86400,
            self::Weekly => 7 * 86400,
        };
    }

    public function labelKey(): string
    {
        return 'jobs.backup.schedule.' . $this->value;
    }
}
