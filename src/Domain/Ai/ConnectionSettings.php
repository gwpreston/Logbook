<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * What an admin sets on a connection (spec.md §7.25 *Connections*), as
 * validated by the form. Secrets are not part of it.
 */
final readonly class ConnectionSettings
{
    public function __construct(
        public string $name,
        public AdapterType $adapter,
        public string $baseUrl,
        public Location $location,
        /** @var list<string> */
        public array $headerNames,
        public int $timeoutSeconds,
        public bool $verifyTls,
        public ?string $caBundle,
        public int $maxRequestMb,
        public ?int $monthlyTokenCap,
        public bool $enabled,
    ) {
    }
}
