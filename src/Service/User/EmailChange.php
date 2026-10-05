<?php

declare(strict_types=1);

namespace Logbook\Service\User;

/**
 * What changing an email address did (spec.md §7.9 *Email addresses*).
 */
enum EmailChange: string
{
    case Unchanged = 'unchanged';
    case Removed = 'removed';
    /** Waiting for its link, which was sent. */
    case Pending = 'pending';
    /** Waiting, but the link could not be sent (no email configured, or the server refused). */
    case PendingNotSent = 'pending_not_sent';
}
