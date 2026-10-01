<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * One message to the owner, already translated and formatted for them
 * (NotificationComposer). Channels pick what suits them: a subject and
 * plain-text body for email, a title, message and click link for a push, the
 * structured items for a webhook.
 */
final readonly class Notification
{
    /**
     * @param list<NotificationItem> $items
     * @param list<array{vehicle_id: int, vehicle: string, kind: string, title: string}> $attention the digest's
     *        *Needs attention* checks (Phase 24, spec.md §7.11)
     */
    public function __construct(
        public NotificationKind $kind,
        /** Subject line / push title. */
        public string $title,
        /** Plain-text body, without the link. */
        public string $message,
        /** Absolute link to the reminders in the app. */
        public ?string $url = null,
        /** Something is overdue: channels with priorities may raise it. */
        public bool $urgent = false,
        public array $items = [],
        public array $attention = [],
    ) {
    }

    /**
     * The body followed by the link, for channels without a click action.
     */
    public function textWithLink(): string
    {
        return $this->url === null ? $this->message : $this->message . "\n\n" . $this->url;
    }
}
