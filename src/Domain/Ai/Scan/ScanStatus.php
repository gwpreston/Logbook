<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Scan;

/**
 * Where a scanned file is (spec.md §6 PendingUpload).
 */
enum ScanStatus: string
{
    /** Uploaded; the model has not answered yet. */
    case Reading = 'reading';
    /** Read: the extraction is stored. */
    case Read = 'read';
    /** Could not be read; the file still waits for an entry. */
    case Failed = 'failed';
    /** Its entry was saved; only the recommendations card remains. */
    case Saved = 'saved';

    public function isPending(): bool
    {
        return $this !== self::Saved;
    }
}
