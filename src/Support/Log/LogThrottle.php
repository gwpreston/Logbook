<?php

declare(strict_types=1);

namespace Logbook\Support\Log;

use Psr\Clock\ClockInterface;

/**
 * "At most once per … per hour" for log lines a request can trigger
 * over and over (spec.md §7.9: an untrusted address sending the proxy
 * header). One small file per key under `var/cache/log-throttle`, so the
 * limit holds across PHP workers; written atomically, and files past their
 * hour are swept now and then so many addresses can't fill the disk. If
 * the directory can't be written, every line is let through rather than
 * lost.
 */
final readonly class LogThrottle
{
    private const int SWEEP_ONE_IN = 50;

    public function __construct(
        private string $directory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Whether the line for $key may be logged now; true at most once per
     * $seconds (and it then counts as logged).
     */
    public function allow(string $key, int $seconds = 3600): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $file = $this->directory . '/' . hash('sha256', $key);
        $last = is_file($file) ? @file_get_contents($file) : false;
        if (is_string($last) && ctype_digit($last) && (int) $last <= $now && $now - (int) $last < $seconds) {
            return false;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return true;
        }
        if (random_int(1, self::SWEEP_ONE_IN) === 1) {
            $this->sweep($now - $seconds);
        }
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, (string) $now) !== false) {
            @rename($temp, $file);
        }
        @unlink($temp);

        return true;
    }

    private function sweep(int $before): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            $modified = @filemtime($file);
            if ($modified !== false && $modified < $before) {
                @unlink($file);
            }
        }
    }
}
