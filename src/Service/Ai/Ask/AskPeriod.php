<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use DateTimeImmutable;
use Logbook\Domain\Trip\TaxYear;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Date\LocalTime;

/**
 * A tool's period (spec.md §7.26): a preset or `from` / `to` calendar
 * dates, resolved against the user's today. Presets that Reports has are
 * its presets, so the link shows the same figure: `this_month`,
 * `this_year` (1 January to today), `last_12_months` (this month and the 11
 * before it, as Reports' *12 months*) and `all_time`. The rest are custom
 * ranges on the page.
 */
final readonly class AskPeriod
{
    public const array PRESETS = [
        'this_month',
        'last_month',
        'this_year',
        'last_year',
        'last_12_months',
        'tax_year',
        'all_time',
    ];

    /** The JSON Schema fragment every period-taking tool shares. */
    public const array SCHEMA = [
        'period' => [
            'type' => 'string',
            'enum' => self::PRESETS,
            'description' => 'A preset period; ignored when from or to is given.',
        ],
        'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD.'],
        'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD.'],
    ];

    private function __construct(
        /** A preset name, or `custom`. */
        public string $preset,
        /** Calendar date, or null for "from the beginning". */
        public ?DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
    }

    /**
     * @param callable(DateTimeImmutable): TaxYear $taxYear the user's tax year containing a date
     * @throws ToolError
     */
    public static function from(
        ToolArguments $arguments,
        DateTimeImmutable $today,
        callable $taxYear,
        string $default = 'last_12_months',
    ): self {
        $from = $arguments->date('from');
        $to = $arguments->date('to');
        if ($to === null && $from !== null) {
            $to = $from > $today ? $from : $today;
        }
        if ($to !== null) {
            if ($from !== null && $from > $to) {
                [$from, $to] = [$to, $from];
            }

            return new self('custom', $from, $to);
        }

        return self::preset($arguments->choice('period', self::PRESETS) ?? $default, $today, $taxYear);
    }

    /**
     * @param callable(DateTimeImmutable): TaxYear $taxYear
     */
    public static function preset(string $preset, DateTimeImmutable $today, callable $taxYear): self
    {
        $year = (int) $today->format('Y');
        $firstOfMonth = $today->setDate($year, (int) $today->format('n'), 1);

        return match ($preset) {
            'this_month' => new self($preset, $firstOfMonth, $today),
            'last_month' => new self(
                $preset,
                LocalTime::addMonths($firstOfMonth, -1),
                $firstOfMonth->modify('-1 day'),
            ),
            'this_year' => new self($preset, $today->setDate($year, 1, 1), $today),
            'last_year' => new self($preset, $today->setDate($year - 1, 1, 1), $today->setDate($year - 1, 12, 31)),
            'tax_year' => (static function () use ($taxYear, $today, $preset): self {
                $tax = $taxYear($today);

                return new self($preset, $tax->start, $tax->lastDay());
            })(),
            'all_time' => new self($preset, null, $today),
            default => new self('last_12_months', LocalTime::addMonths($firstOfMonth, -11), $today),
        };
    }

    public function contains(DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return ($this->from === null || $day >= $this->from->format('Y-m-d')) && $day <= $this->to->format('Y-m-d');
    }

    public function reportPeriod(): ReportPeriod
    {
        $range = $this->reportRange();

        return new ReportPeriod($range ?? ReportRange::Custom, $this->from, $this->to);
    }

    /**
     * The Reports query for the same period: its preset where there is
     * one, else a custom range.
     *
     * @return array<string, string>
     */
    public function reportQuery(): array
    {
        $range = $this->reportRange();
        if ($range !== null) {
            return ['range' => $range->value];
        }
        $query = ['range' => ReportRange::Custom->value];
        if ($this->from !== null) {
            $query['from'] = $this->from->format('Y-m-d');
        }

        return $query + ['to' => $this->to->format('Y-m-d')];
    }

    /**
     * @return array{preset: string, from: ?string, to: string}
     */
    public function toArray(): array
    {
        return ['preset' => $this->preset, 'from' => $this->from?->format('Y-m-d'), 'to' => $this->to->format('Y-m-d')];
    }

    private function reportRange(): ?ReportRange
    {
        return match ($this->preset) {
            'this_month' => ReportRange::ThisMonth,
            'this_year' => ReportRange::ThisYear,
            'last_12_months' => ReportRange::TwelveMonths,
            'all_time' => ReportRange::AllTime,
            default => null,
        };
    }
}
