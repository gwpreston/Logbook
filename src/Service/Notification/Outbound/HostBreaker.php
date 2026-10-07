<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Outbound;

/**
 * The per-run circuit breaker for scheduled sends (spec.md §7.11
 * *Unreachable services in a run*, Phase 36.4, #264). While a job runs,
 * a host (host and port) that failed 3 times without answering is skipped
 * for the rest of the run, the failure alert sent after it included
 * (Phase 37, #267), so a service that is down for everyone costs
 * three timeouts, not one per user. Armed by the job runner for a run
 * only: tests, checks on saving and *Find my chat* are never skipped.
 *
 * Holds state for one run, so it is not readonly; one per process (DI).
 */
final class HostBreaker
{
    public const int AFTER = 3;

    private bool $armed = false;
    /** @var array<string, int> */
    private array $failures = [];

    public function arm(): void
    {
        $this->armed = true;
        $this->failures = [];
    }

    public function disarm(): void
    {
        $this->armed = false;
        $this->failures = [];
    }

    public function skips(string $url): bool
    {
        $host = self::host($url);

        return $this->armed && $host !== null && ($this->failures[$host] ?? 0) >= self::AFTER;
    }

    /**
     * No answer came (a timeout, a connection or TLS failure); an HTTP
     * error is an answer and never counts.
     */
    public function unanswered(string $url): void
    {
        $host = self::host($url);
        if ($this->armed && $host !== null) {
            $this->failures[$host] = ($this->failures[$host] ?? 0) + 1;
        }
    }

    private static function host(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');

        return strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
    }
}
