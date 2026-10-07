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
        /**
         * Not sent because the destination was refused before any request
         * (the policy, or a name that did not resolve): it never counts
         * towards switching a channel off (spec.md §7.11).
         */
        public bool $refused = false,
    ) {
    }

    public static function refused(string $channel, string $error): self
    {
        return new self($channel, false, $error, true);
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
