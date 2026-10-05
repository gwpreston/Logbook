<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use Logbook\Domain\User\User;

/**
 * Who a notification is for, and their own delivery details (spec.md
 * §7.11 *Channels per user*). The instance defaults (MAIL_TO, NTFY_URL's
 * topic, GOTIFY_TOKEN) are an admin's only, so a household topic is not
 * flooded by everyone's cars.
 */
final readonly class Recipient
{
    public function __construct(
        public int $userId,
        public string $name,
        /** Their confirmed email address; null = the server default (MAIL_TO), for admins. */
        public ?string $email = null,
        public bool $isAdmin = true,
        public string $username = '',
        /** A personal ntfy topic URL, replacing NTFY_URL for them. */
        public ?string $ntfyUrl = null,
        /** A personal Gotify application token, replacing GOTIFY_TOKEN for them. */
        public ?string $gotifyToken = null,
    ) {
    }

    public static function of(User $user, NotificationPreferences $preferences): self
    {
        return new self(
            $user->id,
            $user->displayName,
            $user->email,
            $user->isAdmin,
            $user->username,
            $preferences->ntfyUrl,
            $preferences->gotifyToken,
        );
    }
}
