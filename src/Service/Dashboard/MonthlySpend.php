<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use DateTimeImmutable;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;

/**
 * The *Monthly spend* widget (spec.md §7.8): Reports' *Last 12 months* for
 * the vehicles in view, every month listed, with a link to Reports for
 * each month (#206).
 */
final readonly class MonthlySpend
{
    public function __construct(
        public Report $report,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->sections() === [];
    }

    /**
     * The currencies with something spent (costs of 0 are valid but draw nothing).
     *
     * @return list<CurrencyReport>
     */
    public function sections(): array
    {
        return array_values(array_filter(
            $this->report->currencies,
            static fn (CurrencyReport $c): bool => $c->spentGroups() !== [],
        ));
    }

    /**
     * Reports for the same vehicles over the 12 months.
     *
     * @return array<string, string>
     */
    public function reportQuery(): array
    {
        return $this->report->filter->toQuery();
    }

    /**
     * Reports for the same vehicles over one calendar month.
     *
     * @return array<string, string>
     */
    public function monthQuery(DateTimeImmutable $month): array
    {
        return (new ReportFilter(ReportPeriod::month($month), $this->report->filter->vehicleId))->toQuery();
    }
}
