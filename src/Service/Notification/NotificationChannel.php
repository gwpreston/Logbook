<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * An outbound way of reaching the owner: email, ntfy, Gotify, a webhook…
 * (spec.md §7.11).
 *
 * The dispatcher only ever sees this interface. To add a channel, implement
 * it, add the class to the `notification.channels` list in
 * config/dependencies.php, and read its own environment variables in the
 * constructor — see docs/notification-channels.md.
 */
interface NotificationChannel
{
    /**
     * Stable identifier, stored in the owner's settings and in
     * reminders.channels_notified: lower-case letters, digits, dashes.
     */
    public function key(): string;

    /**
     * Name shown in Settings: a translation key or a product name ("Gotify").
     */
    public function label(): string;

    /**
     * Whether the server has what this channel needs (its environment
     * variables). An unconfigured channel is never used, even if enabled.
     */
    public function isConfigured(): bool;

    /**
     * Deliver one notification. Report failure through the result; any
     * exception thrown is also caught and logged by the dispatcher.
     */
    public function send(Notification $notification, Recipient $recipient): DeliveryResult;
}
