<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use DateTimeInterface;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Station\PricePoint;
use Logbook\Service\Station\StationSummary;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A station's price history (spec.md §7.33): what the user paid there per
 * grade, per litre (in their volume unit) or per kWh, in the currency of
 * the grade they buy most there. The table beside it is the no-JS fallback.
 */
final readonly class StationChart
{
    private const array SERIES_COLOURS = ['accent', 'c-maint', 'c-tax', 'c-ins', 'red', 'green', 'amber'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * One chart per kind of energy with prices (liquid first), keyed `liquid`
     * or `electric`.
     *
     * @return array<string, LineChart>
     */
    public function build(StationSummary $summary, DisplayPreferences $preferences): array
    {
        $main = $summary->mainGrade();
        if ($main === null) {
            return [];
        }
        $currency = $main->currency;
        $unit = $preferences->volumeUnit;

        /** @var array<string, array<string, list<PricePoint>>> $byKind kind → grade code ('' = none) → points */
        $byKind = [];
        foreach ($summary->history as $point) {
            if ($point->currency !== $currency) {
                continue;
            }
            $kind = $point->fuel->isElectric() ? 'electric' : 'liquid';
            $byKind[$kind][$point->grade->value ?? ''][] = $point;
        }

        $charts = [];
        foreach (['liquid', 'electric'] as $kind) {
            if (!isset($byKind[$kind])) {
                continue;
            }
            $electric = $kind === 'electric';
            $label = $this->translator->trans('fuel.chart.price_axis', [
                'unit' => $this->translator->trans('units.symbol.' . ($electric ? 'kwh' : $unit->value)),
            ]);
            $chart = new LineChart($preferences, $label, 3, $currency);
            $series = $byKind[$kind];
            $colour = 0;
            foreach (FuelGrade::cases() as $grade) {
                if (isset($series[$grade->value])) {
                    $chart->addSeries(
                        $this->translator->trans($grade->shortLabelKey()),
                        self::points($series[$grade->value], $electric, $unit->litresPerUnit()),
                        self::SERIES_COLOURS[$colour++ % count(self::SERIES_COLOURS)],
                    );
                }
            }
            if (isset($series[''])) {
                $chart->addSeries(
                    $this->translator->trans('fuel.grade_not_recorded'),
                    self::points($series[''], $electric, $unit->litresPerUnit()),
                    'muted',
                );
            }
            $charts[$kind] = $chart;
        }

        return $charts;
    }

    /**
     * @param list<PricePoint> $list
     * @return list<array{0: DateTimeInterface, 1: float}>
     */
    private static function points(array $list, bool $electric, float $litresPerUnit): array
    {
        $points = [];
        foreach ($list as $point) {
            $points[] = [$point->filledAt, $electric ? (float) $point->price : (float) $point->price * $litresPerUnit];
        }

        return $points;
    }
}
