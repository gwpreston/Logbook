<?php

declare(strict_types=1);

namespace Logbook\Domain\User;

use DateTimeImmutable;

/**
 * A one-time link an admin made (spec.md §6 Invitation, §7.9): valid for
 * seven days, once. Only a keyed hash of its token is stored.
 */
final readonly class Invitation
{
    public const int VALID_DAYS = 7;
    /** A break-glass sign-in link (`login`) lasts ten minutes (spec.md §7.9). */
    public const int LOGIN_VALID_MINUTES = 10;

    public function __construct(
        public int $id,
        public InvitationKind $kind,
        public int $createdBy,
        /** The user a reset is for; null for an invite. */
        public ?int $userId,
        public string $username,
        public string $displayName,
        public bool $isAdmin,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
        public ?DateTimeImmutable $revokedAt,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isOpen(DateTimeImmutable $now): bool
    {
        return $this->usedAt === null && $this->revokedAt === null && $now < $this->expiresAt;
    }
}
