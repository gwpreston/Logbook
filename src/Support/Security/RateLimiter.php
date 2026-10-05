<?php

declare(strict_types=1);

namespace Logbook\Support\Security;

use JsonException;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;

/**
 * A sliding-window limit per key (spec.md §7.9 *Forgotten password*: 5
 * requests per client address per 15 minutes, 3 emails per account per
 * hour). Like FailedKeyThrottle, one small JSON file per bucket and key
 * (named by their hash) in the cache directory, under an exclusive lock;
 * no new service to run.
 */
final readonly class RateLimiter
{
    private string $directory;

    public function __construct(AppSettings $settings, private ClockInterface $clock)
    {
        $this->directory = $settings->cacheDir . '/rate-limit';
    }

    /**
     * Count one attempt, unless $max were already made in the last
     * $windowSeconds: true when this one is allowed.
     */
    public function attempt(string $bucket, string $key, int $max, int $windowSeconds): bool
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0o775, true);
        }
        $handle = @fopen($this->file($bucket, $key), 'c+');
        if ($handle === false) {
            // Never let the limiter's storage stop the request (as FailedKeyThrottle).
            return true;
        }

        try {
            flock($handle, LOCK_EX);
            $contents = stream_get_contents($handle);
            $now = $this->clock->now()->getTimestamp();
            $recent = array_values(array_filter(
                self::decode($contents === false ? '' : $contents),
                static fn (int $at): bool => $at > $now - $windowSeconds,
            ));
            $allowed = count($recent) < $max;
            if ($allowed) {
                $recent[] = $now;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($recent, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<int>
     */
    private static function decode(string $contents): array
    {
        try {
            $data = $contents === '' ? [] : json_decode($contents, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = [];
        }

        return is_array($data) ? array_values(array_filter($data, is_int(...))) : [];
    }

    private function file(string $bucket, string $key): string
    {
        return $this->directory . '/' . hash('sha256', $bucket . "\0" . $key) . '.json';
    }
}
