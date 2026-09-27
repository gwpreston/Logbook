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
