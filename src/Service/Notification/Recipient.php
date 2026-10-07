<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use Logbook\Domain\User\User;

/**
 * Who a notification is for (spec.md §7.11 *Channels per user*). Their
 * channels are their own (Phase 36.2): the registry finds them by the user
 * id; whether they are an admin decides the default email recipient and
 * whether the destination policy applies.
 */
final readonly class Recipient
{
    public function __construct(
        public int $userId,
        public string $name,
        /** Their confirmed email address; null = the default recipient for admins, for admins. */
        public ?string $email = null,
        public bool $isAdmin = false,
        public string $username = '',
    ) {
    }

    public static function of(User $user): self
    {
        return new self($user->id, $user->displayName, $user->email, $user->isAdmin, $user->username);
    }
}
