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
    /** The modules a new install switched off at setup (spec.md §7.10): the list behind Settings → Modules' note. */
    public const string INSTALL_OFF_SETTING = 'features_install_off';

    /**
     * Phase 49 (decided 2026-10-14, #413): what a new install switches off.
     * The AI modules are not in the list and keep their own defaults.
     *
     * @var list<Feature>
     */
    private const array OFF_ON_NEW_INSTALL = [
        Feature::Tyres,
        Feature::Finance,
        Feature::Incidents,
        Feature::Issues,
        // Phase 50.1 (#428).
        Feature::Parts,
        Feature::Stations,
        Feature::Trips,
    ];

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
     * First-run setup (spec.md §7.10): write `features` with fewer modules
     * on, unless the setting is already there. A module whose
     * FEATURES_<MODULE> variable is set keeps what the variable says. The
     * modules switched off are recorded for Settings → Modules' note.
     */
    public function startNewInstall(): void
    {
        if ($this->settings->find(self::SETTING) !== null) {
            return;
        }

        $states = [];
        $off = [];
        foreach (Feature::cases() as $feature) {
            $byInstall = in_array($feature, self::OFF_ON_NEW_INSTALL, true) && !$this->env->has($feature->envName());
            $states[$feature->value] = $byInstall ? false : $this->default($feature);
            if ($byInstall) {
                $off[] = $feature->value;
            }
        }

        $this->settings->save(self::SETTING, $states);
        $this->settings->save(self::INSTALL_OFF_SETTING, $off);
    }

    /**
     * The modules still off that the install switched off at setup: where
     * Settings → Modules says so. None for an install set up before.
     *
     * @return list<Feature>
     */
    public function offSinceInstall(): array
    {
        $stored = $this->settings->find(self::INSTALL_OFF_SETTING)?->value;
        if (!is_array($stored)) {
            return [];
        }
        $own = $this->own();

        return array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => in_array($f->value, $stored, true) && !$own[$f->value],
        ));
    }

    /**
     * Every module's state in one read.
     *
     * @return array<string, bool> keyed by Feature value
     */
    public function all(): array
    {
        $states = $this->own();
        foreach (Feature::cases() as $feature) {
            $parent = $feature->requires();
            if ($parent !== null && !$states[$parent->value]) {
                $states[$feature->value] = false;
            }
        }

        return $states;
    }

    /**
     * Every module's own switch, before a module it is part of switches it
     * off (Feature::requires()): what Settings → Modules shows and saves, so
     * switching fuel off and on again keeps the stations switch as it was.
     *
     * @return array<string, bool> keyed by Feature value
     */
    public function own(): array
    {
        $stored = $this->settings->find(self::SETTING)?->value;
        $states = [];
        foreach (Feature::cases() as $feature) {
            $former = $feature->formerName();
            $value = is_array($stored) ? $stored[$feature->value] ?? ($former === null ? null : $stored[$former] ?? null) : null;
            $states[$feature->value] = is_bool($value) ? $value : $this->default($feature);
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

    /**
     * FEATURES_<MODULE>, else the variable under the module's former name
     * (FEATURES_AI_SCAN, #517), else the module's own default.
     */
    private function default(Feature $feature): bool
    {
        $former = $feature->formerName();
        if ($former !== null && !$this->env->has($feature->envName())) {
            return $this->env->bool('FEATURES_' . strtoupper($former), $feature->isOnByDefault());
        }

        return $this->env->bool($feature->envName(), $feature->isOnByDefault());
    }
}
