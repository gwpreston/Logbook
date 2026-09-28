<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

/**
 * What an import does with one CSV row (spec.md §7.13).
 */
enum ImportRowStatus: string
{
    case Import = 'import';
    /** Fails validation; listed with its errors, imported only when skipping is confirmed (it is skipped). */
    case Invalid = 'invalid';
    /** The same entry exists already, or earlier in the file. */
    case Duplicate = 'duplicate';
    /** An odometer reading a fill-up or service creates itself. */
    case Implied = 'implied';
}
