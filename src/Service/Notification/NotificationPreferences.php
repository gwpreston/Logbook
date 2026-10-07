<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * An owner's delivery choices (spec.md §7.11), stored as the
 * `notifications` user setting. Their email address is on the user from
 * Phase 33.1 (§6 User `email`); their ntfy, Gotify and webhook channels
 * are rows of their own from Phase 36.2 (§6 NotificationChannel).
 */
final readonly class NotificationPreferences
{
    public function __construct(
        /**
         * @var list<string>|null keys of the enabled channels among `email`
         *                        and `webhook` (the server's); null until the
         *                        owner chooses, meaning both
         */
        public ?array $channels = null,
        /** Send the monthly "what's due this month" digest. */
        public bool $digest = false,
        /**
         * A Gotify token the Phase 36.2 migration could not seal (no
         * `SESSION_SECRET`, #230): kept where it was, never sent, until the
         * user saves a Gotify token on Account → Notifications.
         */
        public ?string $legacyGotifyToken = null,
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

    public function withChannel(string $channelKey, bool $enabled): self
    {
        $channels = array_values(array_diff($this->channels ?? ['email', 'webhook'], [$channelKey]));
        if ($enabled) {
            $channels[] = $channelKey;
        }

        return new self($channels, $this->digest, $this->legacyGotifyToken);
    }

    public function withDigest(bool $digest): self
    {
        return new self($this->channels, $digest, $this->legacyGotifyToken);
    }

    public function withoutLegacyGotifyToken(): self
    {
        return new self($this->channels, $this->digest);
    }

    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $channels = $value['channels'] ?? null;
        $token = $value['gotify_token'] ?? null;

        return new self(
            is_array($channels) ? array_values(array_filter($channels, is_string(...))) : null,
            ($value['digest'] ?? false) === true,
            is_string($token) && $token !== '' ? $token : null,
        );
    }

    /**
     * @return array{channels: list<string>|null, digest: bool, gotify_token?: string}
     */
    public function toArray(): array
    {
        $array = ['channels' => $this->channels, 'digest' => $this->digest];
        if ($this->legacyGotifyToken !== null) {
            $array['gotify_token'] = $this->legacyGotifyToken;
        }

        return $array;
    }
}
