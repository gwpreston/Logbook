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
    ) {
    }

    public function isEnabled(string $channelKey): bool
    {
        return $this->channels === null || in_array($channelKey, $this->channels, true);
    }

    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $channels = $value['channels'] ?? null;
        $email = $value['email'] ?? null;

        return new self(
            is_array($channels) ? array_values(array_filter($channels, is_string(...))) : null,
            is_string($email) && $email !== '' ? $email : null,
            ($value['digest'] ?? false) === true,
        );
    }

    /**
     * @return array{channels: list<string>|null, email: string|null, digest: bool}
     */
    public function toArray(): array
    {
        return ['channels' => $this->channels, 'email' => $this->email, 'digest' => $this->digest];
    }
}
