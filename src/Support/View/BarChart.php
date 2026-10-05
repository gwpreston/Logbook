<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use JsonSerializable;
use Logbook\Support\Display\DisplayPreferences;

/**
 * Data for a (stacked) bar chart over labelled categories, rendered by
 * assets/js/app.js with Chart.js. Like LineChart it is progressive
 * enhancement: every bar chart sits next to a table with the same figures.
 * Labels arrive already formatted and values already in display units.
 */
final class BarChart implements JsonSerializable
{
    /** @var list<array{label: string, color: string, type: string, values: list<?float>}> */
    private array $series = [];
    /** @var list<bool> bars drawn hatched (a partial period), one per label */
    private array $hatched = [];

    /**
     * @param list<string> $labels one per bar, e.g. localised month names
     */
    public function __construct(
        private readonly DisplayPreferences $preferences,
        private readonly array $labels,
        private readonly int $decimals,
        /** Currency code when values are money. */
        private readonly ?string $currency = null,
        private readonly bool $stacked = true,
        /** Axis title when values are not money, e.g. "mpg (UK)". */
        private readonly ?string $unit = null,
    ) {
    }

    /**
     * @param list<?float> $values one per label; null leaves a gap
     * @param string $color a colour token from app.css, e.g. "c-fuel"
     * @param bool $line drawn as a line over the bars (never stacked)
     */
    public function addSeries(string $label, array $values, string $color, bool $line = false): self
    {
        $this->series[] = [
            'label' => $label,
            'color' => $color,
            'type' => $line ? 'line' : 'bar',
            'values' => array_map(static fn (?float $v): ?float => $v === null ? null : round($v, 6), $values),
        ];

        return $this;
    }

    /**
     * Hatch the bars of partial periods (spec.md §7.35: a year so far).
     *
     * @param list<bool> $hatched one per label
     */
    public function hatch(array $hatched): self
    {
        $this->hatched = $hatched;

        return $this;
    }

    /**
     * Worth drawing: some bar above zero.
     */
    public function hasData(): bool
    {
        foreach ($this->series as $series) {
            foreach ($series['values'] as $value) {
                if ($value > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'bar',
            'locale' => str_replace('_', '-', $this->preferences->locale),
            'labels' => $this->labels,
            'decimals' => $this->decimals,
            'currency' => $this->currency,
            'stacked' => $this->stacked,
            'unit' => $this->unit,
            'series' => $this->series,
            'hatched' => $this->hatched,
        ];
    }
}
