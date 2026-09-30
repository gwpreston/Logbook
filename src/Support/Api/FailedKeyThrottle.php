<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use JsonException;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;

/**
 * Slows down guessing API keys (spec.md §7.20): after MAX_FAILURES failed
 * keys from one address within WINDOW_SECONDS, that address is refused for
 * BLOCK_SECONDS. One small JSON file per address (named by its hash) in the
 * cache directory, under an exclusive lock; no new service to run.
 */
final readonly class FailedKeyThrottle
{
    public const int MAX_FAILURES = 20;
    public const int WINDOW_SECONDS = 600;
    public const int BLOCK_SECONDS = 600;

    private string $directory;

    public function __construct(AppSettings $settings, private ClockInterface $clock)
    {
        $this->directory = $settings->cacheDir . '/api-throttle';
    }

    /**
     * Seconds until the address may try again, or null when it may now.
     */
    public function blockedFor(string $address): ?int
    {
        $state = $this->read($address);
        $left = $state['blocked_until'] - $this->clock->now()->getTimestamp();

        return $left > 0 ? $left : null;
    }

    /**
     * Count a failed key; returns true when this failure starts a block.
     */
    public function recordFailure(string $address): bool
    {
        return $this->update($address, function (array $state): array {
            $now = $this->clock->now()->getTimestamp();
            $failures = array_values(array_filter(
                $state['failures'],
                static fn (int $at): bool => $at > $now - self::WINDOW_SECONDS,
            ));
            $failures[] = $now;
            $blocked = count($failures) >= self::MAX_FAILURES;

            return [
                [
                    'failures' => $blocked ? [] : $failures,
                    'blocked_until' => $blocked ? $now + self::BLOCK_SECONDS : $state['blocked_until'],
                ],
                $blocked,
            ];
        });
    }

    /**
     * @return array{failures: list<int>, blocked_until: int}
     */
    private function read(string $address): array
    {
        $contents = @file_get_contents($this->file($address));

        return self::decode($contents === false ? '' : $contents);
    }

    /**
     * @param callable(array{failures: list<int>, blocked_until: int}): array{
     *     0: array{failures: list<int>, blocked_until: int},
     *     1: bool,
     * } $change
     */
    private function update(string $address, callable $change): bool
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0o775, true);
        }
        $handle = @fopen($this->file($address), 'c+');
        if ($handle === false) {
            // Never let the throttle's storage stop the API; failures are still logged.
            return false;
        }

        try {
            flock($handle, LOCK_EX);
            $contents = stream_get_contents($handle);
            [$state, $blocked] = $change(self::decode($contents === false ? '' : $contents));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $blocked;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array{failures: list<int>, blocked_until: int}
     */
    private static function decode(string $contents): array
    {
        try {
            $data = $contents === '' ? [] : json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = [];
        }
        $failures = is_array($data) && is_array($data['failures'] ?? null) ? $data['failures'] : [];

        return [
            'failures' => array_values(array_filter($failures, is_int(...))),
            'blocked_until' => is_array($data) && is_int($data['blocked_until'] ?? null) ? $data['blocked_until'] : 0,
        ];
    }

    private function file(string $address): string
    {
        return $this->directory . '/' . hash('sha256', $address) . '.json';
    }
}
