<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use DateTimeInterface;
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

    public function __construct(
        private readonly DisplayPreferences $preferences,
        /** Axis title, e.g. "mpg (UK)". */
        private readonly string $unitLabel,
        private readonly int $decimals,
        /** Currency code when values are money (formatted as currency). */
        private readonly ?string $currency = null,
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

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'locale' => str_replace('_', '-', $this->preferences->locale),
            'timeZone' => $this->preferences->timezone,
            'unit' => $this->unitLabel,
            'decimals' => $this->decimals,
            'currency' => $this->currency,
            'series' => $this->series,
        ];
    }
}
