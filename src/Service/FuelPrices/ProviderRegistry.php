<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * The price providers this install knows (spec.md §7.34 *Providers*).
 */
final readonly class ProviderRegistry
{
    /** @var array<string, PriceProvider> */
    private array $providers;

    /**
     * @param list<PriceProvider> $providers
     */
    public function __construct(array $providers)
    {
        $byCode = [];
        foreach ($providers as $provider) {
            $byCode[$provider->code()] = $provider;
        }
        $this->providers = $byCode;
    }

    public function get(?string $code): ?PriceProvider
    {
        return $code === null ? null : ($this->providers[$code] ?? null);
    }

    /**
     * @return list<PriceProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
