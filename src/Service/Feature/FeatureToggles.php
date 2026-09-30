<?php

declare(strict_types=1);

namespace Logbook\Service\Feature;

use Logbook\Domain\Feature\Feature;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Config\Env;

/**
 * Which modules are switched on (spec.md §7.10): the global `features`
 * setting (module → bool) wins, then FEATURES_<MODULE>, then on.
 *
 * Consulted by the route gate (FeatureGateMiddleware), the navigation
 * (`feature_enabled()` in templates), the dashboard, reports and reminders.
 */
final readonly class FeatureToggles
{
    public const string SETTING = 'features';

    public function __construct(
        private SettingRepository $settings,
        private Env $env,
    ) {
    }

    public function isEnabled(Feature $feature): bool
    {
        return $this->all()[$feature->value];
    }

    /**
     * Every module's state in one read.
     *
     * @return array<string, bool> keyed by Feature value
     */
    public function all(): array
    {
        $stored = $this->settings->find(self::SETTING)?->value;
        $states = [];
        foreach (Feature::cases() as $feature) {
            $value = is_array($stored) ? ($stored[$feature->value] ?? null) : null;
            $states[$feature->value] = is_bool($value) ? $value : $this->env->bool($feature->envName(), $feature->isOnByDefault());
        }

        return $states;
    }

    /**
     * Save every module's state (Settings → Modules). Modules left out are
     * switched off. Nothing is deleted: switching a module back on restores
     * it as it was.
     *
     * @param list<Feature> $enabled
     */
    public function save(array $enabled): void
    {
        $states = [];
        foreach (Feature::cases() as $feature) {
            $states[$feature->value] = in_array($feature, $enabled, true);
        }

        $this->settings->save(self::SETTING, $states);
    }
}
