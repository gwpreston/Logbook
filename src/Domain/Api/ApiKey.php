<?php

declare(strict_types=1);

namespace Logbook\Domain\Api;

use DateTimeImmutable;

/**
 * A stored API key (spec.md §6 ApiKey). The token is never stored; only
 * its keyed hash, which stays inside the repository and the key service.
 */
final readonly class ApiKey
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $name,
        public ApiScope $scope,
        /** UTC instants. */
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
        public ?DateTimeImmutable $revokedAt,
    ) {
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
