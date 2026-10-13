<?php

declare(strict_types=1);

namespace Logbook\Domain\Backup;

/**
 * How a recorded backup was made (spec.md §7.13 *How old is the last
 * backup?*). A pre-restore safety copy is none of these: it never counts.
 */
enum BackupKind: string
{
    /** Settings → Backup → *Download backup*. */
    case Download = 'download';
    /** The `backup` job (§7.30), on its schedule or by *Run now*. */
    case Scheduled = 'scheduled';
    /** `php bin/backup.php create`. */
    case Cli = 'cli';
}
