<?php

declare(strict_types=1);

namespace Logbook\Service\Insights;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attention\AttentionSettingsStore;
use Logbook\Service\Attention\DriftFinding;
use Logbook\Service\Attention\TrendChecks;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\Display\DisplayFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * *Economy up* (spec.md §7.8, Phase 42): *Needs attention*'s economy drift
 * (§7.24 item 7) judged for an improvement, per vehicle and series, by the
 * vehicle owner's thresholds and time zone whoever looks, so it never
 * disagrees with the drift item. The likely causes that make sense for an
 * improvement follow the figures, and the drift item's season sentence
 * when last year's months couldn't be compared.
 */
final readonly class EconomyUp
{
    public function __construct(
        private FeatureToggles $features,
        private FuelService $fuel,
        private TrendChecks $trends,
        private AttentionSettingsStore $settings,
        private UserDirectory $directory,
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<Insight>
     */
    public function forVehicles(User $user, array $vehicles): array
    {
        if (!$this->features->isEnabled(Feature::Fuel)) {
            return [];
        }
        $enabled = $this->features->all();
        $now = $this->clock->now();
        $insights = [];
        foreach ($vehicles as $vehicle) {
            $owner = $vehicle->userId === $user->id ? $user : $this->directory->find($vehicle->userId) ?? $user;
            $findings = $this->trends->improvements(
                $vehicle,
                $this->fuel->history($vehicle),
                $this->settings->thresholds($owner->id),
                $enabled,
                $now,
                $owner->preferences->timeZone(),
            );
            foreach ($findings as $finding) {
                $insights[] = $this->insight($vehicle, $finding);
            }
        }

        return $insights;
    }

    private function insight(Vehicle $vehicle, DriftFinding $finding): Insight
    {
        $recent = $this->formatter->economyValue($finding->recentDistanceKm, $finding->recentVolume, $finding->kind);
        $baseline = $this->formatter->economyValue($finding->baselineDistanceKm, $finding->baselineVolume, $finding->kind);
        // From the two figures as shown, as the drift item (it reads right in the viewer's unit).
        $percent = $recent === null || $baseline === null || $baseline == 0.0
            ? 0
            : (int) round(abs($recent - $baseline) / $baseline * 100);

        return new Insight(
            InsightKind::EconomyUp,
            InsightTone::Good,
            'insights.economy_up.title',
            ['percent' => $percent],
            'insights.economy_up.body',
            [
                'vehicle' => $vehicle->name(),
                'electric' => $finding->kind === EnergyKind::Electric ? 'yes' : 'no',
                'tanks' => $finding->tanks,
                'recent' => $this->formatter->economy($finding->recentDistanceKm, $finding->recentVolume, $finding->kind),
                'baseline' => $this->formatter->economy($finding->baselineDistanceKm, $finding->baselineVolume, $finding->kind),
                'extra' => implode('', array_map(static fn (string $s): string => ' ' . $s, $this->extra($finding))),
            ],
            'fuel.index',
            ['id' => $vehicle->id],
            vehicleId: $vehicle->id,
        );
    }

    /**
     * The causes that apply, then the season sentence when it applies.
     *
     * @return list<string>
     */
    private function extra(DriftFinding $finding): array
    {
        $sentences = [];
        if ($finding->gradeFrom !== null && $finding->gradeTo !== null) {
            $sentences[] = $this->translator->trans('attention.drift.cause.grade', [
                'from' => $this->translator->trans('fuel.grade_short.' . $finding->gradeFrom->value),
                'to' => $this->translator->trans('fuel.grade_short.' . $finding->gradeTo->value),
            ]);
        }
        if ($finding->tyresFittedOn !== null) {
            $sentences[] = $this->translator->trans('attention.drift.cause.tyres', [
                'date' => $this->formatter->date($finding->tyresFittedOn),
            ]);
        }
        if ($finding->longTanks) {
            $sentences[] = $this->translator->trans('insights.economy_up.long_tanks');
        }
        if (!$finding->seasonChecked) {
            $sentences[] = $this->translator->trans('attention.drift.season');
        }

        return $sentences;
    }
}
