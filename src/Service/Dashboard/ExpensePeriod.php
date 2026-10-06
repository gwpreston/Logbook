<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use DateTimeImmutable;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;

/**
 * The periods the *Expense breakdown* widget offers (spec.md §7.8, #204),
 * chosen with `?expenses=`. Each is the Reports preset of the same name, so
 * the widget's figures and its links to Reports agree.
 */
enum ExpensePeriod: string
{
    case ThisMonth = 'this_month';
    case TwelveMonths = 'last_12_months';
    case ThisYear = 'this_year';

    public const self DEFAULT = self::TwelveMonths;

    /**
     * From the query: anything unknown (or repeated, which arrives as an
     * array) is the default.
     */
    public static function chosen(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::DEFAULT;
    }

    public function range(): ReportRange
    {
        return match ($this) {
            self::ThisMonth => ReportRange::ThisMonth,
            self::TwelveMonths => ReportRange::TwelveMonths,
            self::ThisYear => ReportRange::ThisYear,
        };
    }

    public function period(DateTimeImmutable $today): ReportPeriod
    {
        return ReportPeriod::preset($this->range(), $today);
    }

    /**
     * For the widget's own links: nothing for the default.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return $this === self::DEFAULT ? [] : ['expenses' => $this->value];
    }
}
