<?php

declare(strict_types=1);

namespace Logbook\Domain\User;

use DateTimeImmutable;

/**
 * A provider account linked to a user (spec.md §6 UserIdentity, §7.9):
 * the issuer and subject the provider vouches for. One provider account
 * reaches at most one user.
 */
final readonly class UserIdentity
{
    /** The single OpenID Connect provider (Phase 23.1). */
    public const string OIDC = 'oidc';

    public function __construct(
        public int $id,
        public int $userId,
        public string $provider,
        public string $issuer,
        public string $subject,
        public ?DateTimeImmutable $lastLoginAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
