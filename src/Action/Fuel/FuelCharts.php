<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use DateTimeImmutable;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Fuel\MonthlyEconomy;
use Logbook\Domain\Fuel\MonthlyEconomyRow;
use Logbook\Domain\Fuel\SegmentCost;
use Logbook\Service\Fuel\FuelHistory;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Money\Currency;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\ElectricEfficiencyUnit;
use Logbook\Support\View\BarChart;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Economy, cost and price trends and economy by month for one kind of
 * energy, in the user's units.
 */
final readonly class FuelCharts
{
    /** Chart tokens (app.css) for grade series, in turn. */
    private const array SERIES_COLOURS = ['accent', 'c-maint', 'c-tax', 'c-ins', 'red', 'green', 'amber'];

    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * Each segment's fuel cost per distance (spec.md §7.3, *Fuel insights*)
     * in the user's distance unit, plus the weighted running average
     * (Σ cost ÷ Σ distance so far).
     *
     * @param list<SegmentCost> $costs oldest first
     */
    public function cost(array $costs, EnergyKind $kind, DisplayPreferences $preferences, string $currency): LineChart
    {
        $kmPerUnit = $preferences->distanceUnit === DistanceUnit::Mile ? DistanceUnit::KM_PER_MILE : 1.0;
        $perTank = [];
        $average = [];
        $cost = 0.0;
        $distance = 0.0;
        foreach ($costs as $segment) {
            $cost += (float) $segment->cost;
            $distance += (float) $segment->distanceKm;
            $perTank[] = [$segment->endedAt, (float) $segment->costPerKm * $kmPerUnit];
            $average[] = [$segment->endedAt, $cost / $distance * $kmPerUnit];
        }

        $label = $this->translator->trans('fuel.chart.cost_axis', ['unit' => $preferences->distanceUnit->value]);
        $electric = $kind === EnergyKind::Electric;

        return (new LineChart($preferences, $label, Currency::fractionDigits($currency) + 1, $currency))
            ->addSeries($this->translator->trans($electric ? 'fuel.chart.per_tank_ev' : 'fuel.chart.per_tank'), $perTank, 'green')
            ->addSeries($this->translator->trans('fuel.chart.average'), $average, 'muted', true);
    }

    /**
     * Economy by month (spec.md §7.3): the average across years as bars,
     * the current and previous year as lines; a month without a figure
     * leaves a gap.
     */
    public function monthly(MonthlyEconomy $months, DisplayPreferences $preferences): BarChart
    {
        $electric = $months->kind === EnergyKind::Electric;
        $efficiency = ElectricEfficiencyUnit::forDistanceUnit($preferences->distanceUnit);
        $consumption = $preferences->consumptionUnit;
        $value = static fn (?MonthlyEconomyRow $row): ?float => $row === null || !$row->hasFigure() ? null : ($electric
            ? $efficiency->fromDistanceAndEnergy((float) $row->distanceKm, (float) $row->volume)
            : $consumption->fromDistanceAndVolume((float) $row->distanceKm, (float) $row->volume));
        $monthNumbers = range(1, 12);

        $chart = new BarChart(
            $preferences,
            array_map(fn (int $m): string => $this->formatter->monthName($m, true), $monthNumbers),
            1,
            null,
            false,
            $this->translator->trans('units.name.' . ($electric ? $efficiency->value : $consumption->value)),
        );
        $chart->addSeries(
            $this->translator->trans('fuel.monthly.average'),
            array_map(static fn (int $m): ?float => $value($months->average($m)), $monthNumbers),
            'accent',
        );
        foreach ([$months->currentYear - 1 => 'c-tax', $months->currentYear => 'green'] as $year => $colour) {
            $values = array_map(static fn (int $m): ?float => $value($months->cell($year, $m)), $monthNumbers);
            if (array_filter($values, static fn (?float $v): bool => $v !== null) !== []) {
                $chart->addSeries((string) $year, $values, $colour, true);
            }
        }

        return $chart;
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
