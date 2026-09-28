<?php

declare(strict_types=1);

namespace Logbook\Action\Dashboard;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Service\Dashboard\VehicleEfficiency;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Units\ElectricEfficiencyUnit;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The efficiency trend widget's chart: each vehicle's per-fill economy over
 * the window, one line per vehicle, for liquid fuel (or electricity when no
 * vehicle has liquid fuel figures). One unit per chart, so kinds never mix.
 */
final readonly class DashboardCharts
{
    private const array COLORS = ['accent', 'c-maint', 'c-ins', 'c-tax', 'c-other'];

    public function __construct(
        private DisplayContext $display,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<VehicleEfficiency> $rows
     */
    public function efficiency(array $rows): ?LineChart
    {
        foreach ([EnergyKind::Liquid, EnergyKind::Electric] as $kind) {
            $chart = $this->chart($kind, array_values(array_filter(
                $rows,
                static fn (VehicleEfficiency $row): bool => $row->kind === $kind,
            )));
            if ($chart->hasData()) {
                return $chart;
            }
        }

        return null;
    }

    /**
     * @param list<VehicleEfficiency> $rows of one kind
     */
    private function chart(EnergyKind $kind, array $rows): LineChart
    {
        $preferences = $this->display->preferences();
        $electric = $kind === EnergyKind::Electric;
        $efficiency = ElectricEfficiencyUnit::forDistanceUnit($preferences->distanceUnit);
        $consumption = $preferences->consumptionUnit;
        $unitKey = $electric ? $efficiency->value : $consumption->value;

        $chart = new LineChart($preferences, $this->translator->trans('units.name.' . $unitKey), 1);
        foreach ($rows as $index => $row) {
            $points = [];
            foreach ($row->measured as $fill) {
                $segment = $fill->segment;
                if ($segment === null) {
                    continue;
                }
                $value = $electric
                    ? $efficiency->fromDistanceAndEnergy((float) $segment->distanceKm, (float) $segment->volume)
                    : $consumption->fromDistanceAndVolume((float) $segment->distanceKm, (float) $segment->volume);
                if ($value !== null) {
                    $points[] = [$segment->endedAt, $value];
                }
            }
            $chart->addSeries($row->vehicle->name(), $points, self::COLORS[$index % count(self::COLORS)]);
        }

        return $chart;
    }
}
