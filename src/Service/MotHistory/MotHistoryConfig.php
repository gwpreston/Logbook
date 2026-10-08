<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Domain\Feature\Feature;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoRestriction;
use Logbook\Service\Feature\FeatureToggles;

/**
 * Whether MOT history is on, and with which provider (spec.md §7.38): only
 * with the `compliance` module on, outside demo mode, and a known provider
 * enabled on Settings → MOT history. Everything else about MOT history
 * asks this first, so nothing appears and nothing is sent while it is off.
 */
final readonly class MotHistoryConfig
{
    public const string SETTING = 'mot_history';
    public const string STATUS = 'mot_history.status';

    public function __construct(
        private SettingRepository $settings,
        private FeatureToggles $features,
        private MotHistoryRegistry $providers,
        private DemoMode $demo,
    ) {
    }

    /** Settings → MOT history is offered to admins. */
    public function available(): bool
    {
        return $this->features->isEnabled(Feature::Compliance) && !$this->demo->blocks(DemoRestriction::Outbound);
    }

    public function providerCode(): ?string
    {
        $stored = $this->settings->find(self::SETTING)?->value;
        $code = is_array($stored) ? ($stored['provider'] ?? null) : null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function saveProvider(?MotHistoryProvider $provider): void
    {
        $this->settings->save(self::SETTING, ['provider' => $provider?->code()]);
    }

    /**
     * The enabled provider, or none while MOT history is off.
     */
    public function provider(): ?MotHistoryProvider
    {
        return $this->available() ? $this->providers->get($this->providerCode()) : null;
    }

    public function enabled(): bool
    {
        return $this->provider() !== null;
    }

    public function status(): MotHistoryStatus
    {
        return MotHistoryStatus::fromStored($this->settings->find(self::STATUS)?->value);
    }

    public function saveStatus(MotHistoryStatus $status): void
    {
        $this->settings->save(self::STATUS, $status->toStored());
    }

    public function registry(): MotHistoryRegistry
    {
        return $this->providers;
    }
}
