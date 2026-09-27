<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Service\Fuel\FuelHistory;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ElectricEfficiencyUnit;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Economy and price trends for one kind of energy, in the user's units.
 */
final readonly class FuelCharts
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * Each measured full-to-full segment, plus the running (weighted) average.
     */
    public function economy(FuelHistory $history, EnergyKind $kind, DisplayPreferences $preferences): LineChart
    {
        $electric = $kind === EnergyKind::Electric;
        $efficiency = ElectricEfficiencyUnit::forDistanceUnit($preferences->distanceUnit);
        $consumption = $preferences->consumptionUnit;
        $unitKey = $electric ? $efficiency->value : $consumption->value;
        $value = static fn (float $km, float $volume): ?float => $electric
            ? $efficiency->fromDistanceAndEnergy($km, $volume)
            : $consumption->fromDistanceAndVolume($km, $volume);

        $perFill = [];
        $average = [];
        $distance = 0.0;
        $volume = 0.0;
        foreach ($history->measured($kind) as $fill) {
            $segment = $fill->segment;
            if ($segment === null) {
                continue;
            }
            $distance += (float) $segment->distanceKm;
            $volume += (float) $segment->volume;
            $point = $value((float) $segment->distanceKm, (float) $segment->volume);
            $running = $value($distance, $volume);
            if ($point !== null && $running !== null) {
                $perFill[] = [$segment->endedAt, $point];
                $average[] = [$segment->endedAt, $running];
            }
        }

        return (new LineChart($preferences, $this->translator->trans('units.name.' . $unitKey), 1))
            ->addSeries($this->translator->trans('fuel.chart.per_fill'), $perFill, 'green')
            ->addSeries($this->translator->trans('fuel.chart.average'), $average, 'muted', true);
    }

    /**
     * Price per litre (per the user's volume unit) or per kWh, per fill-up.
     */
    public function price(FuelHistory $history, EnergyKind $kind, DisplayPreferences $preferences, string $currency): LineChart
    {
        $electric = $kind === EnergyKind::Electric;
        $unit = $preferences->volumeUnit;
        $points = [];
        foreach ($history->ofKind($kind) as $fill) {
            $perLitre = (float) $fill->entry->data->pricePerUnit;
            $points[] = [$fill->entry->data->filledAt, $electric ? $perLitre : $perLitre * $unit->litresPerUnit()];
        }

        $label = $this->translator->trans('fuel.chart.price_axis', [
            'unit' => $this->translator->trans('units.symbol.' . ($electric ? 'kwh' : $unit->value)),
        ]);

        return (new LineChart($preferences, $label, 3, $currency))
            ->addSeries($this->translator->trans('fuel.chart.price'), $points);
    }
}
