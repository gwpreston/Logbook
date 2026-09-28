<?php

declare(strict_types=1);

namespace Logbook\Domain\Attachment;

/**
 * The kinds of entry a file can be attached to (the `owner_type` column).
 * `odometer` is for manual readings only: a derived reading's files are its
 * entry's.
 */
enum AttachmentOwner: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Compliance = 'compliance';
    case Expense = 'expense';
    case Odometer = 'odometer';
}
