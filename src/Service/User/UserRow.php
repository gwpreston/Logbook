<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use DateTimeImmutable;
use Logbook\Domain\User\User;

/**
 * One line of Settings → Users: the account and when it was last used.
 */
final readonly class UserRow
{
    public function __construct(
        public User $user,
        public ?DateTimeImmutable $lastSeenAt,
    ) {
    }
}
