<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareAllowance;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Access\ShareSummaryLine;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleGrant;
use Logbook\Domain\Access\VehicleShare;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Service\Feature\FeatureToggles;

/**
 * What a share allows, in plain words (spec.md §7.21 *What a share
 * allows*). Each line is a *Can* exactly when the grant the access policy
 * builds from the share allows the line's ability, so the words can't
 * drift from the behaviour; lines for switched-off modules are left out.
 */
final readonly class ShareSummary
{
    /**
     * The lines that describe one ability, in the spec's order, with the
     * module each needs. `own` closes the list, after `reminders`. A test
     * fails when an ability has no line here.
     *
     * @var array<string, array{VehicleAbility, Feature|null}>
     */
    public const array ABILITY_LINES = [
        'see' => [VehicleAbility::View, null],
        'documents' => [VehicleAbility::View, Feature::Compliance],
        'add' => [VehicleAbility::Log, null],
        'change_any' => [VehicleAbility::Manage, null],
        'costs' => [VehicleAbility::ViewCosts, null],
        'tools' => [VehicleAbility::Manage, null],
        'trips' => [VehicleAbility::ViewOthersTrips, Feature::Trips],
        'incidents' => [VehicleAbility::ViewIncidentDetails, Feature::Incidents],
        'own' => [VehicleAbility::Own, null],
    ];

    public function __construct(private FeatureToggles $features)
    {
    }

    public function of(ShareLevel $level, bool $canSeeCosts, bool $notify): ShareAllowance
    {
        $canSeeCosts = $canSeeCosts || $level->alwaysSeesCosts();
        $at = new DateTimeImmutable('@0');
        $grant = new VehicleGrant(
            0,
            VehicleStatus::Active,
            false,
            new VehicleShare(0, 0, 0, $level, $canSeeCosts, $notify, $at, $at),
        );
        $on = $this->features->all();
        $yes = static fn (Feature $feature): string => $on[$feature->value] ? 'yes' : 'no';
        $params = [
            'see' => ['reminders' => $yes(Feature::Reminders)],
            'add' => ['fuel' => $yes(Feature::Fuel), 'maintenance' => $yes(Feature::Maintenance)],
            'costs' => ['reports' => $yes(Feature::Reports)],
        ];

        $lines = [];
        foreach (self::ABILITY_LINES as $key => [$ability, $module]) {
            if ($module !== null && !$on[$module->value]) {
                continue;
            }
            if ($key === 'own' && $on[Feature::Reminders->value]) {
                $lines[] = new ShareSummaryLine('reminders', $grant->notifies(), access: false);
            }
            // The own-entry rules (EntryAccess), each before the wider line
            // it is part of: under Log one changes what one added, and
            // without costs one still sees the amounts one typed.
            $log = $grant->allows(VehicleAbility::Log);
            if ($key === 'change_any' && $log && !$grant->allows(VehicleAbility::Manage)) {
                $lines[] = new ShareSummaryLine('change_own', true, within: 'change_any');
            }
            if ($key === 'costs' && $log && !$grant->allows(VehicleAbility::ViewCosts)) {
                $lines[] = new ShareSummaryLine('own_amounts', true, within: 'costs');
            }
            $lines[] = new ShareSummaryLine($key, $grant->allows($ability), $params[$key] ?? []);
        }

        return new ShareAllowance($level, $canSeeCosts, $notify, $lines);
    }

    /**
     * Every level's summary without its ticks, for *What each level
     * allows* (the sharing page without JS).
     *
     * @return list<ShareAllowance>
     */
    public function levels(): array
    {
        return array_map(fn (ShareLevel $level): ShareAllowance => $this->of($level, false, false), ShareLevel::cases());
    }

    /**
     * Every state the sharing form can be in, keyed by its controls as the
     * form's Alpine code spells them (`log-0-1`: level, costs ticked,
     * reminders ticked), for the live summary.
     *
     * @return array<string, ShareAllowance>
     */
    public function states(): array
    {
        $states = [];
        foreach (ShareLevel::cases() as $level) {
            foreach ([false, true] as $costs) {
                foreach ([false, true] as $notify) {
                    $states[sprintf('%s-%d-%d', $level->value, $costs ? 1 : 0, $notify ? 1 : 0)]
                        = $this->of($level, $costs, $notify);
                }
            }
        }

        return $states;
    }
}
