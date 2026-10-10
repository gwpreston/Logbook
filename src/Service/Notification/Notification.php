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
     * @param list<array<string, mixed>> $lastMonth the digest's *Last month*, per vehicle (Phase 43)
     * @param array<string, mixed>|null $fleet the digest's fleet line, with two or more vehicles (Phase 43)
     * @param list<array{vehicle_id: int, vehicle: string, open: int}> $issues open issues per vehicle (Phase 43)
     * @param list<array{kind: string, source: string, vehicle_ids: list<int>, title: string, body: string}> $insights
     *        the digest's insights (Phase 43)
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
        /** The recipient's locale it was written in, for a sender's own words ("…and 3 more"). */
        public ?string $locale = null,
        public array $lastMonth = [],
        public ?array $fleet = null,
        public array $issues = [],
        public array $insights = [],
    ) {
    }

    /**
     * spec.md §7.11 *Urgency*: overdue reminders and a failed job are high,
     * the monthly digest low, everything else normal.
     */
    public function urgency(): Urgency
    {
        return match ($this->kind) {
            NotificationKind::Reminders => $this->urgent ? Urgency::High : Urgency::Normal,
            NotificationKind::JobFailed => Urgency::High,
            NotificationKind::Digest => Urgency::Low,
            NotificationKind::Test,
            NotificationKind::PriceAlert,
            NotificationKind::ChannelOff,
            NotificationKind::WebhookPaused => Urgency::Normal,
        };
    }

    /**
     * The body's lines: each reminder and each check is one, so a message
     * can be cut between them.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $this->message));
    }

    /**
     * The body followed by the link, for channels without a click action.
     */
    public function textWithLink(): string
    {
        return $this->url === null ? $this->message : $this->message . "\n\n" . $this->url;
    }
}
