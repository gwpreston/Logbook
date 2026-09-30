<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * An owner's delivery choices (spec.md §7.11), stored as the
 * `notifications` user setting.
 */
final readonly class NotificationPreferences
{
    public function __construct(
        /**
         * @var list<string>|null keys of the enabled channels; null until the
         *                        owner chooses, meaning every configured one
         */
        public ?array $channels = null,
        /** Where email goes; null = MAIL_TO. */
        public ?string $email = null,
        /** Send the monthly "what's due this month" digest. */
        public bool $digest = false,
        /** A personal ntfy topic URL (Phase 19); null = NTFY_URL, for admins. */
        public ?string $ntfyUrl = null,
        /** A personal Gotify application token (Phase 19); null = GOTIFY_TOKEN, for admins. */
        public ?string $gotifyToken = null,
    ) {
    }

    /**
     * What a user created by setup or an invitation starts with (Phase
     * 21.1): the digest on, everything else the default. Users from before
     * 2.1.0 have no stored row or an explicit "off", and keep it.
     */
    public static function forNewUser(): self
    {
        return new self(digest: true);
    }

    public function isEnabled(string $channelKey): bool
    {
        return $this->channels === null || in_array($channelKey, $this->channels, true);
    }

    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $channels = $value['channels'] ?? null;
        $text = static fn (string $key): ?string => is_string($value[$key] ?? null) && $value[$key] !== '' ? $value[$key] : null;

        return new self(
            is_array($channels) ? array_values(array_filter($channels, is_string(...))) : null,
            $text('email'),
            ($value['digest'] ?? false) === true,
            $text('ntfy_url'),
            $text('gotify_token'),
        );
    }

    /**
     * @return array{
     *     channels: list<string>|null,
     *     email: string|null,
     *     digest: bool,
     *     ntfy_url: string|null,
     *     gotify_token: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'channels' => $this->channels,
            'email' => $this->email,
            'digest' => $this->digest,
            'ntfy_url' => $this->ntfyUrl,
            'gotify_token' => $this->gotifyToken,
        ];
    }
}
