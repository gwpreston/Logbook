<?php

declare(strict_types=1);

namespace Logbook\Action\Forecast;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Service\Forecast\ForecastMonthTotal;
use Logbook\Service\Forecast\ForecastTotals;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Currency;
use Logbook\Support\View\BarChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Planned and fuel per month, stacked, for one currency's forecast (spec.md
 * §7.18). The page shows the same figures as a table.
 */
final readonly class ForecastCharts
{
    public function __construct(
        private DisplayFormatter $formatter,
        private DisplayContext $display,
        private TranslatorInterface $translator,
    ) {
    }

    public function monthly(ForecastTotals $totals): BarChart
    {
        $chart = new BarChart(
            $this->display->preferences(),
            array_map(fn (ForecastMonthTotal $m): string => $this->formatter->month($m->month), $totals->months),
            Currency::fractionDigits($totals->currency),
            $totals->currency,
        );
        $chart->addSeries(
            $this->translator->trans('coming_up.summary.planned'),
            array_map(static fn (ForecastMonthTotal $m): float => $m->planned->toFloat(), $totals->months),
            CostGroup::Maintenance->color(),
        );
        if ($totals->hasFuel) {
            $chart->addSeries(
                $this->translator->trans('coming_up.summary.fuel'),
                array_map(static fn (ForecastMonthTotal $m): float => $m->fuel->toFloat(), $totals->months),
                CostGroup::Fuel->color(),
            );
        }

        return $chart;
    }
}
