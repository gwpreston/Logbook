<?php

declare(strict_types=1);

namespace Logbook\Domain\Trip;

use DateTimeImmutable;
use InvalidArgumentException;
use Logbook\Support\Date\LocalTime;

/**
 * A tax year (spec.md §7.23): twelve months from the user's tax year start
 * (`MM-DD`). A year starting on 1 January is labelled by its calendar year
 * ("2026"); any other by the two years it spans ("2026/27", as HMRC writes
 * 6 April 2026 to 5 April 2027). Dates are calendar dates, midnight UTC.
 */
final readonly class TaxYear
{
    private function __construct(
        /** First day, inclusive. */
        public DateTimeImmutable $start,
        /** The next year's first day, exclusive. */
        public DateTimeImmutable $end,
    ) {
    }

    /**
     * "04-06" and "01-01" are valid; 29 February is not (it has no day in most years).
     */
    public static function isValidStart(string $monthDay): bool
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $monthDay, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[1], (int) $m[2], 2025);
    }

    /**
     * The tax year a calendar date falls in.
     */
    public static function containing(DateTimeImmutable $date, string $startMonthDay): self
    {
        $year = (int) $date->format('Y');
        $start = self::startIn($year, $startMonthDay);
        if ($date < $start) {
            $start = self::startIn($year - 1, $startMonthDay);
        }

        return self::startingIn((int) $start->format('Y'), $startMonthDay);
    }

    /**
     * The tax year that starts in $year ("2026" → 6 Apr 2026 to 5 Apr 2027).
     */
    public static function startingIn(int $year, string $startMonthDay): self
    {
        return new self(self::startIn($year, $startMonthDay), self::startIn($year + 1, $startMonthDay));
    }

    public function startYear(): int
    {
        return (int) $this->start->format('Y');
    }

    public function previous(): self
    {
        return self::startingIn($this->startYear() - 1, $this->start->format('m-d'));
    }

    public function contains(DateTimeImmutable $date): bool
    {
        return $date >= $this->start && $date < $this->end;
    }

    /**
     * The last day, inclusive (5 April for a GB year).
     */
    public function lastDay(): DateTimeImmutable
    {
        return $this->end->modify('-1 day');
    }

    /**
     * "2026" or "2026/27".
     */
    public function label(): string
    {
        $year = $this->startYear();

        return $this->start->format('m-d') === '01-01'
            ? (string) $year
            : sprintf('%d/%02d', $year, ($year + 1) % 100);
    }

    /**
     * For file names: "2026" or "2026-27".
     */
    public function slug(): string
    {
        return str_replace('/', '-', $this->label());
    }

    private static function startIn(int $year, string $startMonthDay): DateTimeImmutable
    {
        if (!self::isValidStart($startMonthDay)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a tax year start (MM-DD).', $startMonthDay));
        }

        return LocalTime::parseDate(sprintf('%04d-%s', $year, $startMonthDay))
            ?? throw new InvalidArgumentException('Invalid tax year start.');
    }
}
