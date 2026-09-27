<?php

declare(strict_types=1);

namespace Logbook\Domain\User;

use DateTimeImmutable;
use Logbook\Support\Display\DisplayPreferences;

/**
 * An account. One owner per instance today; the table is shaped for more.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        /** Always lower-case (see Username::normalise()). */
        public string $username,
        public string $passwordHash,
        public string $displayName,
        public DisplayPreferences $preferences,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
