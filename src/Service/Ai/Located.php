<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\Location;

/**
 * Where a connection's host was found to run (spec.md §7.25 *Where it runs*).
 */
final readonly class Located
{
    public function __construct(
        public Location $location,
        public string $host,
        /** @var list<string> what the host resolved to; empty when it did not */
        public array $addresses,
    ) {
    }

    public function resolved(): bool
    {
        return $this->addresses !== [];
    }
}
