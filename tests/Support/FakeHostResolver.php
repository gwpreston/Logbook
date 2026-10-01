<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Support\Net\HostResolver;

/**
 * DNS for tests: each host resolves to what it was given; anything else
 * does not resolve (IP literals resolve to themselves, as on a real system).
 */
final class FakeHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    public array $hosts = [
        'api.openai.com' => ['104.18.7.192'],
        'api.anthropic.com' => ['160.79.104.10'],
        'generativelanguage.googleapis.com' => ['142.250.180.10'],
        'openrouter.ai' => ['104.18.2.115'],
        'localhost' => ['127.0.0.1'],
        'host.docker.internal' => ['172.17.0.1'],
    ];

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->hosts[$host] ?? [];
    }
}
