<?php

declare(strict_types=1);

namespace Logbook\Service\Feature;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Config\Env;

/**
 * Which modules are switched on (spec.md §7.10): the global `features`
 * setting (module → bool) wins, then FEATURES_<MODULE>, then on.
 *
 * Phase 5 consults it for the dashboard and reports; Phase 6 adds the
 * settings screen and gates routes and navigation.
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
            $states[$feature->value] = is_bool($value) ? $value : $this->env->bool($feature->envName(), true);
        }

        return $states;
    }
}
