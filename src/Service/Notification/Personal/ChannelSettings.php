<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use SensitiveParameter;

/**
 * One user's settings for a channel, ready to send with: the visible
 * values and the opened secrets. Never stored or shown as a whole.
 */
final readonly class ChannelSettings
{
    /**
     * @param array<string, scalar> $values
     * @param array<string, string> $secrets
     */
    public function __construct(
        public array $values,
        #[SensitiveParameter] public array $secrets = [],
    ) {
    }

    public function value(string $field): ?string
    {
        $value = $this->values[$field] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function int(string $field, int $default): int
    {
        $value = $this->values[$field] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function secret(string $field): ?string
    {
        $value = $this->secrets[$field] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @return list<string> every secret, for redacting errors
     */
    public function secretValues(): array
    {
        return array_values(array_filter($this->secrets, static fn (string $v): bool => $v !== ''));
    }
}
