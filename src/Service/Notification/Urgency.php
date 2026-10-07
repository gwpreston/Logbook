<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * How loudly a notification may arrive (spec.md §7.11 *Urgency*): the
 * services with priorities or silent sends (Telegram, Pushover) read it.
 */
enum Urgency: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
