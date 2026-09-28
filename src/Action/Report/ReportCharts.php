<?php

declare(strict_types=1);

namespace Logbook\Action\Report;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\MonthTotal;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Currency;
use Logbook\Support\View\BarChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Spend per month, stacked by cost group, for one currency's figures.
 */
final readonly class ReportCharts
{
    public function __construct(
        private DisplayFormatter $formatter,
        private DisplayContext $display,
        private TranslatorInterface $translator,
    ) {
    }

    public function monthly(CurrencyReport $section): BarChart
    {
        $chart = new BarChart(
            $this->display->preferences(),
            array_map(fn (MonthTotal $m): string => $this->formatter->month($m->month), $section->months),
            Currency::fractionDigits($section->currency),
            $section->currency,
        );

        foreach (CostGroup::cases() as $group) {
            if ($section->group($group)->isZero()) {
                continue;
            }
            $chart->addSeries(
                $this->translator->trans('expense.group.' . $group->value),
                array_map(static fn (MonthTotal $m): float => $m->amount($group)->toFloat(), $section->months),
                $group->color(),
            );
        }

        return $chart;
    }
}
