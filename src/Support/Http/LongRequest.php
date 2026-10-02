<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

/**
 * A request that runs a job (spec.md §7.30): keep going if the browser
 * goes away, within a time limit. Shared hosts often disable these
 * functions, and PHP 8 throws on calling a disabled one, so each is
 * checked first; without them the request runs under PHP's own limits.
 */
final class LongRequest
{
    public static function allow(int $seconds): void
    {
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            set_time_limit($seconds);
        }
    }
}
