<?php

declare(strict_types=1);

namespace Logbook\Action\Dashboard;

use Logbook\Action\Report\ReportCharts;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Service\Dashboard\MileageSummary;
use Logbook\Service\Dashboard\MonthDistance;
use Logbook\Service\Dashboard\MonthlySpend;
use Logbook\Service\Dashboard\VehicleEfficiency;
use Logbook\Service\Report\MonthTotal;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Units\ElectricEfficiencyUnit;
use Logbook\Support\View\BarChart;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The dashboard's charts. Efficiency trend: each vehicle's per-fill economy
 * over the window, one line per vehicle, for liquid fuel (or electricity
 * when no vehicle has liquid fuel figures); one unit per chart, so kinds
 * never mix. Mileage: distance per month over the last 12 months.
 */
final readonly class DashboardCharts
{
    private const array COLORS = ['accent', 'c-maint', 'c-ins', 'c-tax', 'c-other'];

    public function __construct(
        private DisplayContext $display,
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
        private ReportCharts $reportCharts,
        private Redirector $redirect,
    ) {
    }

    /**
     * Distance per month in the owner's unit; null when nothing was driven.
     */
    public function mileage(?MileageSummary $summary): ?BarChart
    {
        if ($summary === null || !$summary->hasMonths()) {
            return null;
        }
        $unit = $this->display->preferences()->distanceUnit;

        return (new BarChart(
            $this->display->preferences(),
            array_map(fn (MonthDistance $m): string => $this->formatter->month($m->month), $summary->months),
            0,
            null,
            false,
        ))->addSeries(
            $this->translator->trans('units.name.' . $unit->value),
            array_map(static fn (MonthDistance $m): float => $unit->fromKm((float) ($m->km ?? '0')), $summary->months),
            'accent',
        );
    }

    /**
     * Monthly spend (spec.md §7.8): the Expenses tab's stacked chart per
     * currency with something spent, each bar linking to Reports for its
     * month (#206).
     *
     * @return array<string, BarChart> keyed by currency code
     */
    public function monthlySpend(?MonthlySpend $spend): array
    {
        if ($spend === null) {
            return [];
        }
        $charts = [];
        foreach ($spend->sections() as $section) {
            $charts[$section->currency] = $this->reportCharts->monthly($section)->links(array_map(
                fn (MonthTotal $m): string => $this->redirect->urlFor('reports.index', [], $spend->monthQuery($m->month)),
                $section->months,
            ));
        }

        return $charts;
    }

    /**
     * @param list<VehicleEfficiency> $rows
     */
    public function efficiency(array $rows): ?LineChart
    {
        foreach (EnergyKind::cases() as $kind) {
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
        $scale = $preferences->economyScale($kind);

        $chart = new LineChart($preferences, $this->translator->trans('units.name.' . $scale->code()), 1);
        foreach ($rows as $index => $row) {
            $points = [];
            foreach ($row->measured as $fill) {
                $segment = $fill->segment;
                if ($segment === null) {
                    continue;
                }
                $value = $scale->value((float) $segment->distanceKm, (float) $segment->volume);
                if ($value !== null) {
                    $points[] = [$segment->endedAt, $value];
                }
            }
            $chart->addSeries($row->vehicle->name(), $points, self::COLORS[$index % count(self::COLORS)]);
        }

        return $chart;
    }
}
