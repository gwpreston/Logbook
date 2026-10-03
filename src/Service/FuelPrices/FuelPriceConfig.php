<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Feature\Feature;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Feature\FeatureToggles;

/**
 * Whether live prices are on, and with which provider (spec.md §7.34):
 * only with the `stations` module on (so `fuel` too) and a known provider
 * enabled on Settings → Fuel prices. Everything else about prices asks
 * this first, so nothing appears and nothing is fetched while it is off.
 */
final readonly class FuelPriceConfig
{
    public const string SETTING = 'fuel_prices';
    public const string SYNC = 'fuel_prices.sync';

    public function __construct(
        private SettingRepository $settings,
        private FeatureToggles $features,
        private ProviderRegistry $providers,
    ) {
    }

    public function settings(): FuelPriceSettings
    {
        return FuelPriceSettings::fromStored($this->settings->find(self::SETTING)?->value);
    }

    public function save(FuelPriceSettings $settings): void
    {
        $this->settings->save(self::SETTING, $settings->toStored());
    }

    /** The `stations` module is on: Settings → Fuel prices is offered to admins. */
    public function available(): bool
    {
        return $this->features->isEnabled(Feature::Stations);
    }

    /**
     * The enabled provider, or none while prices are off.
     */
    public function provider(): ?PriceProvider
    {
        return $this->available() ? $this->providers->get($this->settings()->provider) : null;
    }

    public function enabled(): bool
    {
        return $this->provider() !== null;
    }

    public function syncState(): SyncState
    {
        return SyncState::fromStored($this->settings->find(self::SYNC)?->value);
    }

    public function saveSyncState(SyncState $state): void
    {
        $this->settings->save(self::SYNC, $state->toStored());
    }

    public function registry(): ProviderRegistry
    {
        return $this->providers;
    }
}
