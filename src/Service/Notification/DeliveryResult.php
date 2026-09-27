<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * What one channel did with one notification.
 */
final readonly class DeliveryResult
{
    private function __construct(
        public string $channel,
        public bool $delivered,
        /** Why it failed (logged; never shown with secrets). */
        public ?string $error = null,
    ) {
    }

    public static function delivered(string $channel): self
    {
        return new self($channel, true);
    }

    public static function failed(string $channel, string $error): self
    {
        return new self($channel, false, $error);
    }
}
