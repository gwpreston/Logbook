<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use DateTimeImmutable;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
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
    /** Chart tokens (app.css) for grade series, in turn. */
    private const array SERIES_COLOURS = ['accent', 'c-maint', 'c-tax', 'c-ins', 'red', 'green', 'amber'];

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
     * Price per litre (per the user's volume unit) or per kWh, per fill-up:
     * one series per grade used (spec.md §7.3) plus "Not recorded", or a
     * single series while no fill-up has a grade.
     */
    public function price(FuelHistory $history, EnergyKind $kind, DisplayPreferences $preferences, string $currency): LineChart
    {
        $electric = $kind === EnergyKind::Electric;
        $unit = $preferences->volumeUnit;
        /** @var array<string, list<array{0: DateTimeImmutable, 1: float}>> $series by grade code ('' = none) */
        $series = [];
        foreach ($history->ofKind($kind) as $fill) {
            $data = $fill->entry->data;
            $perLitre = (float) $data->pricePerUnit;
            $series[$data->grade->value ?? ''][] = [$data->filledAt, $electric ? $perLitre : $perLitre * $unit->litresPerUnit()];
        }

        $label = $this->translator->trans('fuel.chart.price_axis', [
            'unit' => $this->translator->trans('units.symbol.' . ($electric ? 'kwh' : $unit->value)),
        ]);
        $chart = new LineChart($preferences, $label, 3, $currency);

        if (array_keys($series) === [''] || $series === []) {
            return $chart->addSeries($this->translator->trans('fuel.chart.price'), $series[''] ?? []);
        }

        // Grades in the enum's order, "not recorded" last.
        $colour = 0;
        foreach (FuelGrade::cases() as $grade) {
            if (isset($series[$grade->value])) {
                $name = $this->translator->trans($grade->shortLabelKey());
                $chart->addSeries($name, $series[$grade->value], self::SERIES_COLOURS[$colour++ % count(self::SERIES_COLOURS)]);
            }
        }
        if (isset($series[''])) {
            $chart->addSeries($this->translator->trans('fuel.grade_not_recorded'), $series[''], 'muted');
        }

        return $chart;
    }
}
