<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * The last call to the provider (spec.md §7.38 *Settings*, the global
 * `mot_history.status`): when, whether it worked, its redacted error, and
 * the last successful call's time, which the keep-alive reads (#327).
 */
final readonly class MotHistoryStatus
{
    /**
     * @param array<string, string> $parameters the error's message parameters, redacted
     */
    public function __construct(
        public ?DateTimeImmutable $lastCallAt = null,
        public ?MotHistoryErrorCode $error = null,
        public array $parameters = [],
        public ?DateTimeImmutable $lastSuccessAt = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->lastCallAt !== null && $this->error === null;
    }

    public function succeeded(DateTimeImmutable $at): self
    {
        return new self($at, null, [], $at);
    }

    /**
     * @param array<string, string> $parameters already redacted
     */
    public function failed(DateTimeImmutable $at, MotHistoryErrorCode $error, array $parameters): self
    {
        return new self($at, $error, $parameters, $this->lastSuccessAt);
    }

    public static function fromStored(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $parameters = [];
        foreach (is_array($value['parameters'] ?? null) ? $value['parameters'] : [] as $name => $parameter) {
            if (is_string($name) && is_string($parameter)) {
                $parameters[$name] = $parameter;
            }
        }

        return new self(
            self::instant($value['last_call_at'] ?? null),
            is_string($value['error'] ?? null) ? MotHistoryErrorCode::tryFrom($value['error']) : null,
            $parameters,
            self::instant($value['last_success_at'] ?? null),
        );
    }

    /**
     * @return array{
     *     last_call_at: string|null,
     *     error: string|null,
     *     parameters: array<string, string>,
     *     last_success_at: string|null,
     * }
     */
    public function toStored(): array
    {
        return [
            'last_call_at' => $this->lastCallAt?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'error' => $this->error?->value,
            'parameters' => $this->parameters,
            'last_success_at' => $this->lastSuccessAt?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
