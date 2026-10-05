<?php

declare(strict_types=1);

namespace Logbook\Domain\User;

use DateTimeImmutable;
use Logbook\Support\Display\DisplayPreferences;

/**
 * An account (spec.md §6 User). Admins run the install (§7.9); a disabled
 * user cannot sign in and their keys and feed stop working.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        /** Always lower-case (see Username::normalise()). */
        public string $username,
        /** Null for a user created through single sign-on who has not set one (Phase 23.1). */
        public ?string $passwordHash,
        public string $displayName,
        public DisplayPreferences $preferences,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public bool $isAdmin = false,
        public ?DateTimeImmutable $disabledAt = null,
        /** Their confirmed address, lower-case (Phase 33.1): reset links, email sign-in, reminders. */
        public ?string $email = null,
        /** An address waiting for its confirmation link, used for nothing else. */
        public ?string $emailPending = null,
        /** The avatar's path under UPLOAD_PATH/avatars, if they have one. */
        public ?string $avatarPath = null,
        public ?DateTimeImmutable $avatarUpdatedAt = null,
    ) {
    }

    public function hasAvatar(): bool
    {
        return $this->avatarPath !== null;
    }

    public function isActive(): bool
    {
        return $this->disabledAt === null;
    }

    public function hasPassword(): bool
    {
        return $this->passwordHash !== null;
    }
}
