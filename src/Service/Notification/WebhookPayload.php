<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * The JSON a webhook receives (spec.md §7.11), the same for the server's
 * webhook and a personal one, for Home Assistant, n8n, Node-RED, a chat
 * bridge…:
 *
 *   {"event": "reminders"|"digest"|"test"|"job_failed"|"price_alert"|"channel_off",
 *    "title": …, "message": …, "url": …, "urgent": bool,
 *    "items": [{"reminder_id", "title", "detail", "status", "due_on"}],
 *    "attention": [{"vehicle_id", "vehicle", "kind", "title"}] (the
 *    digest's checks, Phase 24; else empty),
 *    "user": {"id", "username", "display_name"}}
 */
final class WebhookPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Notification $notification, Recipient $recipient): array
    {
        return [
            'event' => $notification->kind->value,
            'title' => $notification->title,
            'message' => $notification->message,
            'url' => $notification->url,
            'urgent' => $notification->urgent,
            'items' => array_map(static fn (NotificationItem $i): array => $i->toArray(), $notification->items),
            'attention' => $notification->attention,
            'user' => ['id' => $recipient->userId, 'username' => $recipient->username, 'display_name' => $recipient->name],
        ];
    }
}
