<?php

declare(strict_types=1);

namespace Logbook\Domain\Notification;

use DateTimeImmutable;

/**
 * One user's channel of one kind (spec.md §6 NotificationChannel). The
 * settings are the kind's visible fields; `_secrets` lists the secret
 * fields that should be saved (one that is missing makes the channel
 * *Needs setup*) and `_imported` marks one made from the server's
 * variables by the Phase 36.2 migration.
 */
final readonly class ChannelRecord
{
    /** Failed sends in a row after which a channel switches itself off (#248). */
    public const int SWITCH_OFF_AFTER = 5;

    public function __construct(
        public int $id,
        public int $userId,
        public string $kind,
        public bool $enabled,
        /** @var array<string, mixed> */
        public array $settings,
        public ?string $lastStatus = null,
        public ?DateTimeImmutable $lastAttemptAt = null,
        public ?string $lastError = null,
        public int $failures = 0,
        public ?DateTimeImmutable $switchedOffAt = null,
    ) {
    }

    /**
     * The visible field values, without the bookkeeping keys.
     *
     * @return array<string, scalar>
     */
    public function values(): array
    {
        $values = [];
        foreach ($this->settings as $name => $value) {
            if (!str_starts_with($name, '_') && is_scalar($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    public function value(string $field): ?string
    {
        $value = $this->settings[$field] ?? null;

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * @return list<string> the secret fields that should be saved
     */
    public function secretFields(): array
    {
        $fields = $this->settings['_secrets'] ?? [];

        return is_array($fields) ? array_values(array_filter($fields, is_string(...))) : [];
    }

    public function switchedOff(): bool
    {
        return $this->switchedOffAt !== null;
    }
}
