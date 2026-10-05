<?php

declare(strict_types=1);

namespace Logbook\Action\Report;

use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostWording;
use Logbook\Service\Report\TruePart;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Money\Currency;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\View\BarChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The true cost trend's charts (spec.md §7.35): per vehicle, cost per
 * distance by calendar year stacked by part, partial years hatched; for
 * the fleet, one line per vehicle. Years under 500 km are left to the
 * table. Values are in the owner's distance unit.
 */
final readonly class TrueCostCharts
{
    private const array COLORS = ['accent', 'c-maint', 'c-ins', 'c-tax', 'c-other'];

    public function __construct(
        private DisplayContext $display,
        private TrueCostWording $wording,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Null when no year has enough distance to draw.
     */
    public function vehicle(VehicleTrueCost $vtc): ?BarChart
    {
        $years = $vtc->chartYears();
        if ($years === []) {
            return null;
        }
        $chart = new BarChart(
            $this->display->preferences(),
            array_map(fn (TrueCost $year): string => $this->wording->label($year), $years),
            Currency::fractionDigits($vtc->currency) + 1,
            $vtc->currency,
        );
        foreach (TruePart::cases() as $part) {
            $values = array_map(fn (TrueCost $year): ?float => $this->perUnit($year->rate($part)), $years);
            if (array_filter($values, static fn (?float $v): bool => $v !== null && $v !== 0.0) !== []) {
                $chart->addSeries($this->wording->part($part), $values, $part->color());
            }
        }

        return $chart->hatch(array_map(static fn (TrueCost $year): bool => $year->period->isPartial(), $years));
    }

    /**
     * One line per vehicle of the currency: total per distance by year.
     *
     * @param list<VehicleTrueCost> $vehicles of one currency
     */
    public function fleet(array $vehicles, string $currency): ?BarChart
    {
        $labels = [];
        foreach ($vehicles as $vtc) {
            foreach ($vtc->chartYears() as $year) {
                $labels[(string) $year->period->year] = true;
            }
        }
        if (count($vehicles) < 2 || $labels === []) {
            return null;
        }
        $labels = array_keys($labels);
        sort($labels);
        $labels = array_map('strval', $labels);
        $chart = new BarChart($this->display->preferences(), $labels, Currency::fractionDigits($currency) + 1, $currency, false);
        foreach ($vehicles as $i => $vtc) {
            $byYear = [];
            foreach ($vtc->chartYears() as $year) {
                $byYear[(string) $year->period->year] = $this->perUnit($year->perKm);
            }
            $chart->addSeries(
                $vtc->vehicle->name(),
                array_map(static fn (string $label): ?float => $byYear[$label] ?? null, $labels),
                self::COLORS[$i % count(self::COLORS)],
                true,
            );
        }

        return $chart;
    }

    public function label(VehicleTrueCost $vtc): string
    {
        return $this->translator->trans('true_cost.chart_label', ['vehicle' => $vtc->vehicle->name()]);
    }

    private function perUnit(?string $perKm): ?float
    {
        if ($perKm === null) {
            return null;
        }

        return $this->display->preferences()->distanceUnit === DistanceUnit::Mile
            ? (float) $perKm * DistanceUnit::KM_PER_MILE
            : (float) $perKm;
    }
}
