<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

/**
 * What a channel field holds (spec.md §7.11 *Definitions*).
 */
enum FieldType: string
{
    /** An http(s) address: a host, no credentials, no fragment. */
    case Url = 'url';
    /** Plain text on one line. */
    case Text = 'text';
    /** A token or key: sealed, never shown again (NotificationSecrets). */
    case Secret = 'secret';
    /** A whole number within the field's range. */
    case Integer = 'integer';
}
