<?php

declare(strict_types=1);

namespace Logbook\Domain\Attachment;

/**
 * The kinds of entry a file can be attached to (the `owner_type` column).
 */
enum AttachmentOwner: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Compliance = 'compliance';
}
