<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;

/**
 * Settings → Fuel prices (spec.md §7.34): the enabled provider (none by
 * default), the refresh interval and the E5 mapping. Stored as the global
 * setting `fuel_prices`.
 */
final readonly class FuelPriceSettings
{
    public const array REFRESH_CHOICES = [30, 60, 120];
    public const int DEFAULT_REFRESH = 60;

    public function __construct(
        public ?string $provider = null,
        public int $refresh = self::DEFAULT_REFRESH,
        public FuelGrade $e5 = FuelGrade::E5_97,
    ) {
    }

    /**
     * @param mixed $value the stored JSON
     */
    public static function fromStored(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $provider = is_string($value['provider'] ?? null) && $value['provider'] !== '' ? $value['provider'] : null;
        $refresh = $value['refresh'] ?? null;
        $e5 = is_string($value['e5'] ?? null) ? FuelGrade::tryFrom($value['e5']) : null;

        return new self(
            $provider,
            is_int($refresh) && in_array($refresh, self::REFRESH_CHOICES, true) ? $refresh : self::DEFAULT_REFRESH,
            $e5 ?? FuelGrade::E5_97,
        );
    }

    /**
     * @return array{provider: string|null, refresh: int, e5: string}
     */
    public function toStored(): array
    {
        return ['provider' => $this->provider, 'refresh' => $this->refresh, 'e5' => $this->e5->value];
    }

    /**
     * The admin's grade choices for a provider's codes.
     *
     * @return array<string, FuelGrade>
     */
    public function chosenGrades(): array
    {
        return ['E5' => $this->e5];
    }

    /**
     * Minutes between runs, never below the provider's minimum.
     */
    public function refreshFor(PriceProvider $provider): int
    {
        return max($this->refresh, $provider->minimumRefreshMinutes());
    }
}
