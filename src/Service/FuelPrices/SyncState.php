<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Where the sync got to (`fuel_prices.sync`, spec.md §7.34): the provider
 * it was for, when the last good sync started (the next incremental one
 * asks for changes since then, less a margin) and when the last full one
 * did.
 */
final readonly class SyncState
{
    public function __construct(
        public ?string $provider = null,
        public ?DateTimeImmutable $lastGood = null,
        public ?DateTimeImmutable $lastFull = null,
    ) {
    }

    public static function fromStored(mixed $value): self
    {
        $value = is_array($value) ? $value : [];

        return new self(
            is_string($value['provider'] ?? null) ? $value['provider'] : null,
            self::time($value['last_good'] ?? null),
            self::time($value['last_full'] ?? null),
        );
    }

    /**
     * @return array{provider: string|null, last_good: string|null, last_full: string|null}
     */
    public function toStored(): array
    {
        return [
            'provider' => $this->provider,
            'last_good' => $this->lastGood?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'last_full' => $this->lastFull?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }

    private static function time(mixed $value): ?DateTimeImmutable
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
