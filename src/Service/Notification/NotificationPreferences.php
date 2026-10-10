<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * An owner's delivery choices (spec.md §7.11), stored as the
 * `notifications` user setting. Their email address is on the user from
 * Phase 33.1 (§6 User `email`); their ntfy, Gotify and webhook channels
 * are rows of their own from Phase 36.2 (§6 NotificationChannel). From
 * Phase 36.4 it also holds what email receives and the quiet hours.
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
        /** What email receives (Phase 36.4); all until the user chooses. */
        public ?ChannelCategories $emailCategories = null,
        /** Null when off (Phase 36.4). */
        public ?QuietHours $quiet = null,
        /**
         * @var list<DigestSection>|null the digest's sections the user
         *      ticked (Phase 43, stored as `digest_include`); null until
         *      they choose, meaning all
         */
        public ?array $digestSections = null,
    ) {
    }

    /**
     * Whether the digest includes $section (spec.md §7.11 *The monthly
     * briefing*): every one until the user chooses.
     */
    public function digestIncludes(DigestSection $section): bool
    {
        return $this->digestSections === null || in_array($section, $this->digestSections, true);
    }

    public function emailCategories(): ChannelCategories
    {
        return $this->emailCategories ?? ChannelCategories::all();
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

        return new self(
            $channels,
            $this->digest,
            $this->legacyGotifyToken,
            $this->emailCategories,
            $this->quiet,
            $this->digestSections,
        );
    }

    public function withDigest(bool $digest): self
    {
        return new self(
            $this->channels,
            $digest,
            $this->legacyGotifyToken,
            $this->emailCategories,
            $this->quiet,
            $this->digestSections,
        );
    }

    public function withoutLegacyGotifyToken(): self
    {
        return new self(
            $this->channels,
            $this->digest,
            null,
            $this->emailCategories,
            $this->quiet,
            $this->digestSections,
        );
    }

    public function withEmailCategories(ChannelCategories $categories): self
    {
        return new self(
            $this->channels,
            $this->digest,
            $this->legacyGotifyToken,
            $categories,
            $this->quiet,
            $this->digestSections,
        );
    }

    public function withQuiet(?QuietHours $quiet): self
    {
        return new self(
            $this->channels,
            $this->digest,
            $this->legacyGotifyToken,
            $this->emailCategories,
            $quiet,
            $this->digestSections,
        );
    }

    /**
     * Every section ticked is stored as "all" (null), so a section added in
     * a later release reaches the user too (as `ChannelCategories`, #268, #372).
     *
     * @param list<DigestSection> $sections
     */
    public function withDigestSections(array $sections): self
    {
        $sections = DigestSection::fromStored(array_map(static fn (DigestSection $s): string => $s->value, $sections)) ?? [];

        return new self(
            $this->channels,
            $this->digest,
            $this->legacyGotifyToken,
            $this->emailCategories,
            $this->quiet,
            count($sections) === count(DigestSection::cases()) ? null : $sections,
        );
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
            isset($value['email_categories']) ? ChannelCategories::fromStored($value['email_categories']) : null,
            QuietHours::fromStored($value['quiet'] ?? null),
            DigestSection::fromStored($value['digest_include'] ?? null),
        );
    }

    /**
     * @return array{
     *     channels: list<string>|null,
     *     digest: bool,
     *     gotify_token?: string,
     *     email_categories?: string,
     *     quiet?: array{start: string, end: string},
     *     digest_include?: list<string>,
     * }
     */
    public function toArray(): array
    {
        $array = ['channels' => $this->channels, 'digest' => $this->digest];
        if ($this->legacyGotifyToken !== null) {
            $array['gotify_token'] = $this->legacyGotifyToken;
        }
        $categories = $this->emailCategories?->toStored();
        if ($categories !== null) {
            $array['email_categories'] = $categories;
        }
        if ($this->quiet !== null) {
            $array['quiet'] = $this->quiet->toStored();
        }
        if ($this->digestSections !== null) {
            $array['digest_include'] = array_map(static fn (DigestSection $s): string => $s->value, $this->digestSections);
        }

        return $array;
    }
}
