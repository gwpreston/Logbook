<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\GroupTotal;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Support\Number\Apportion;

/**
 * The *Expense breakdown* widget (spec.md §7.8): a report's spend by group
 * for the chosen period, per currency, with whole-percentage shares that
 * add up to 100. Every figure is the report's own.
 */
final readonly class ExpenseBreakdown
{
    /**
     * @param list<BreakdownSection> $sections one per currency with something spent
     */
    private function __construct(
        public ExpensePeriod $period,
        public Report $report,
        public array $sections,
    ) {
    }

    public static function of(ExpensePeriod $period, Report $report): self
    {
        $sections = [];
        foreach ($report->currencies as $currency) {
            if (!$currency->isEmpty()) {
                $sections[] = self::section($currency);
            }
        }

        return new self($period, $report, $sections);
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }

    /**
     * Reports for the same vehicles and period (and one group, for a row).
     *
     * @return array<string, string>
     */
    public function reportQuery(?CostGroup $group = null): array
    {
        $filter = $this->report->filter;

        return (new ReportFilter($filter->period, $filter->vehicleId, false, $group))->toQuery();
    }

    private static function section(CurrencyReport $currency): BreakdownSection
    {
        $spent = $currency->spentGroups();
        $percents = Apportion::percentages(array_map(static fn (GroupTotal $g): int => $g->amount->micros, $spent));

        return new BreakdownSection(
            $currency->currency,
            $currency->total,
            array_map(
                static fn (GroupTotal $g, int $percent): GroupShare => new GroupShare($g->group, $g->amount, $percent),
                $spent,
                $percents,
            ),
        );
    }
}
