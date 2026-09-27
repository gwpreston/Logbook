<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * Who a notification is for, and their own delivery details.
 */
final readonly class Recipient
{
    public function __construct(
        public int $userId,
        public string $name,
        /** Their email address; null = the server default (MAIL_TO). */
        public ?string $email = null,
    ) {
    }
}
