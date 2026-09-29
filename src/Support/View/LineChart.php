<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use Logbook\Support\Display\DisplayPreferences;

/**
 * Data for a time-series line chart, rendered by assets/js/app.js with
 * Chart.js (progressive enhancement: every chart sits next to a list or
 * table with the same figures). Values arrive already converted to the
 * user's units; the script only formats and draws them.
 *
 * Templates emit it as `data-chart="{{ chart|json_encode }}"` on a canvas.
 */
final class LineChart implements JsonSerializable
{
    /** @var list<array{label: string, color: string, dashed: bool, points: list<array{0: int, 1: float}>}> */
    private array $series = [];
    private bool $print = false;

    public function __construct(
        private readonly DisplayPreferences $preferences,
        /** Axis title, e.g. "mpg (UK)". */
        private readonly string $unitLabel,
        private readonly int $decimals,
        /** Currency code when values are money (formatted as currency). */
        private readonly ?string $currency = null,
        /**
         * Points are calendar dates (midnight UTC), not instants: shown in UTC
         * so no time zone moves them to the day before.
         */
        private readonly bool $calendarDates = false,
    ) {
    }

    /**
     * @param list<array{0: DateTimeInterface, 1: float}> $points
     * @param string $color a colour token from app.css, e.g. "accent"
     */
    public function addSeries(string $label, array $points, string $color = 'accent', bool $dashed = false): self
    {
        $this->series[] = [
            'label' => $label,
            'color' => $color,
            'dashed' => $dashed,
            'points' => array_map(
                static fn (array $point): array => [$point[0]->getTimestamp() * 1000, round($point[1], 6)],
                $points,
            ),
        ];

        return $this;
    }

    /**
     * Drawn in the print palette (black on white) whatever the theme: for
     * a chart that is printed (the sale pack), since a canvas keeps the
     * colours it was drawn with.
     */
    public function forPrint(): self
    {
        $this->print = true;

        return $this;
    }

    /**
     * Worth drawing: at least two points in some series.
     */
    public function hasData(): bool
    {
        foreach ($this->series as $series) {
            if (count($series['points']) >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Points across every series (a price chart split by grade is worth
     * drawing from two fills on, even when each grade has one).
     */
    public function pointCount(): int
    {
        return array_sum(array_map(static fn (array $series): int => count($series['points']), $this->series));
    }

    /** Axis title, e.g. "mpg (UK)": the table's caption. */
    public function unitLabel(): string
    {
        return $this->unitLabel;
    }

    public function decimals(): int
    {
        return $this->decimals;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    /** Points are calendar dates (shown as UTC dates), not instants. */
    public function hasCalendarDates(): bool
    {
        return $this->calendarDates;
    }

    /**
     * @return list<string> one per series, in the order of each row's values
     */
    public function seriesLabels(): array
    {
        return array_map(static fn (array $series): string => $series['label'], $this->series);
    }

    /**
     * The chart's figures as table rows (spec.md §8 *Printing reports*): one
     * per point in time, newest first, with each series' value at that time
     * or null where a series has none.
     *
     * @return list<LineChartRow>
     */
    public function rows(): array
    {
        /** @var array<int, list<?float>> $byTime */
        $byTime = [];
        $empty = array_fill(0, count($this->series), null);
        foreach ($this->series as $index => $series) {
            foreach ($series['points'] as [$time, $value]) {
                $byTime[$time] ??= $empty;
                $byTime[$time][$index] = $value;
            }
        }
        krsort($byTime);

        $utc = new DateTimeZone('UTC');
        $rows = [];
        foreach ($byTime as $time => $values) {
            $at = (new DateTimeImmutable('@' . intdiv($time, 1000)))->setTimezone($utc);
            $rows[] = new LineChartRow($at, $values);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'locale' => str_replace('_', '-', $this->preferences->locale),
            'timeZone' => $this->calendarDates ? 'UTC' : $this->preferences->timezone,
            'unit' => $this->unitLabel,
            'decimals' => $this->decimals,
            'currency' => $this->currency,
            'series' => $this->series,
            'print' => $this->print,
        ];
    }
}
