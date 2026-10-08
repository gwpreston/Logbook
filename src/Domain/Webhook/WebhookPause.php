<?php

declare(strict_types=1);

namespace Logbook\Domain\Webhook;

/**
 * Why a webhook is paused (spec.md §6 Webhook `paused_reason`).
 */
enum WebhookPause: string
{
    /** *Pause* on the Webhooks page. */
    case User = 'user';
    /** 50 consecutive failed attempts (#293). */
    case Failures = 'failures';
    /** Restored from a backup without its secret (#289): *Needs a new secret*. */
    case Restored = 'restored';
}
