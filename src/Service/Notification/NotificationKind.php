<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

enum NotificationKind: string
{
    /** Reminders that have just become due or overdue. */
    case Reminders = 'reminders';
    /** The monthly "what's due this month" summary. */
    case Digest = 'digest';
    /** Sent from Settings to check the channels work. */
    case Test = 'test';
    /** A background job failed twice in a row (admins, Phase 28.1). */
    case JobFailed = 'job_failed';
    /** A favourite station's listed price dropped below the user's alert (Phase 30.2). */
    case PriceAlert = 'price_alert';
    /** A personal channel switched itself off after failing 5 times in a row (Phase 36.2). */
    case ChannelOff = 'channel_off';

    /**
     * Whether a send counts towards a channel's last result and failures
     * (spec.md §7.11): tests and the switched-off notice never do.
     */
    public function counts(): bool
    {
        return $this !== self::Test && $this !== self::ChannelOff;
    }
}
