<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use DateTimeImmutable;
use Logbook\Domain\Backup\BackupRecord;

/**
 * How old the last backup is, for one viewer (spec.md §7.13): what the
 * Backup page, the banner, the digest line and the API read show.
 */
final readonly class BackupStatus
{
    /**
     * @param array{by: int, at: DateTimeImmutable}|null $elsewhere
     */
    public function __construct(
        public ?BackupRecord $last,
        /** Calendar days since the last backup in the viewer's time zone; null without one. */
        public ?int $ageDays,
        /** The threshold; null for *Never*. */
        public ?int $warnAfterDays,
        public ?array $elsewhere,
        /** Whether the banner's condition holds (before *Dismiss* and demo mode). */
        public bool $warning,
    ) {
    }
}
