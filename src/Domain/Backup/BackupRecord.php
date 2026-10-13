<?php

declare(strict_types=1);

namespace Logbook\Domain\Backup;

use DateTimeImmutable;

/**
 * The newest completed backup (spec.md §6 `backup.last`, §7.13).
 */
final readonly class BackupRecord
{
    public function __construct(
        /** When the archive was complete (UTC). */
        public DateTimeImmutable $at,
        public BackupKind $kind,
        /** The archive's size in bytes. */
        public int $size,
    ) {
    }

    public function isNewerThan(?self $other): bool
    {
        return $other === null || $this->at > $other->at;
    }
}
