<?php

declare(strict_types=1);

namespace Logbook\Support\Net;

use Psr\Clock\ClockInterface;

/**
 * Remembers each name's answer, a failure included, for a minute, so a
 * scheduler pass or a page asks the system resolver once per name: with a
 * slow or dead resolver every lookup can block for many seconds (Phase
 * 36.2's performance review). Safe for the destination policy, which pins
 * the request to an address it checked rather than trusting the name.
 */
final class CachingHostResolver implements HostResolver
{
    public const int TTL_SECONDS = 60;
    private const int MAX_ENTRIES = 256;

    /** @var array<string, array{at: int, addresses: list<string>}> */
    private array $cache = [];

    public function __construct(private readonly HostResolver $inner, private readonly ClockInterface $clock)
    {
    }

    public function resolve(string $host): array
    {
        $key = strtolower($host);
        $now = $this->clock->now()->getTimestamp();
        $hit = $this->cache[$key] ?? null;
        if ($hit !== null && $now - $hit['at'] < self::TTL_SECONDS) {
            return $hit['addresses'];
        }
        if (count($this->cache) >= self::MAX_ENTRIES) {
            $this->cache = [];
        }
        $addresses = $this->inner->resolve($host);
        $this->cache[$key] = ['at' => $now, 'addresses' => $addresses];

        return $addresses;
    }
}
