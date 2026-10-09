<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Support\Security\RateLimiter;

/**
 * How often one person may ask DVSA (spec.md §7.38 *Requests*): *Look up*
 * and *Fetch* together, at most 20 in 10 minutes and 200 in a day per
 * user, so nobody can use up the install's shared quota for everyone else
 * (500,000 a day, 15 a second). The job isn't counted: it has its own
 * window and stops at DVSA's throttle.
 */
final readonly class MotHistoryLimit
{
    public const int SHORT_MAX = 20;
    public const int SHORT_SECONDS = 600;
    public const int DAY_MAX = 200;

    public function __construct(private RateLimiter $limiter)
    {
    }

    /**
     * Counts one request: true when it may go to DVSA.
     */
    public function allow(int $userId): bool
    {
        $key = (string) $userId;

        return $this->limiter->attempt('mot_history.short', $key, self::SHORT_MAX, self::SHORT_SECONDS)
            && $this->limiter->attempt('mot_history.day', $key, self::DAY_MAX, 86400);
    }
}
