<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use DateTimeInterface;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\FuelPrices\DailyPrice;
use Logbook\Service\Station\PricePoint;
use Logbook\Service\Station\StationSummary;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\EconomyScale;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A station's price history (spec.md §7.33): what the user paid there per
 * grade, per litre (in their volume unit) or per kWh, in the currency of
 * the grade they buy most there. The table beside it is the no-JS fallback.
 * A linked station adds each grade's listed price (the daily close) as a
 * dashed second series (Phase 30.2, spec.md §7.34 *Station page*).
 */
final readonly class StationChart
{
    private const array SERIES_COLOURS = ['accent', 'c-maint', 'c-tax', 'c-ins', 'red', 'green', 'amber'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * One chart per kind of energy with prices (liquid first), keyed by the
     * kind: `liquid`, `electric` or `gas`.
     *
     * @param array<string, list<DailyPrice>> $listed the listed price by grade and day
     * @return array<string, LineChart>
     */
    public function build(
        ?StationSummary $summary,
        DisplayPreferences $preferences,
        array $listed = [],
        ?string $listedCurrency = null,
    ): array {
        $main = $summary?->mainGrade();
        $currency = $main->currency ?? $listedCurrency;
        if ($currency === null) {
            return [];
        }
        if ($listedCurrency !== $currency) {
            $listed = [];
        }

        /** @var array<string, array<string, list<PricePoint>>> $byKind kind → grade code ('' = none) → points */
        $byKind = [];
        if ($listed !== []) {
            $byKind['liquid'] ??= [];
        }
        foreach ($summary->history ?? [] as $point) {
            if ($point->currency !== $currency) {
                continue;
            }
            $kind = $point->fuel->kind()->value;
            $byKind[$kind][$point->grade->value ?? ''][] = $point;
        }

        $charts = [];
        foreach (EnergyKind::cases() as $energy) {
            $kind = $energy->value;
            if (!isset($byKind[$kind])) {
                continue;
            }
            $scale = $preferences->economyScale($energy);
            $label = $this->translator->trans('fuel.chart.price_axis', [
                'unit' => $this->translator->trans('units.symbol.' . $scale->quantityCode()),
            ]);
            $chart = new LineChart($preferences, $label, 3, $currency);
            $series = $byKind[$kind];
            $colour = 0;
            foreach (FuelGrade::cases() as $grade) {
                $paid = isset($series[$grade->value]);
                // The listed feed has liquid fuel only.
                $prices = $energy === EnergyKind::Liquid ? ($listed[$grade->value] ?? []) : [];
                if (!$paid && $prices === []) {
                    continue;
                }
                $tone = self::SERIES_COLOURS[$colour++ % count(self::SERIES_COLOURS)];
                if ($paid) {
                    $chart->addSeries(
                        $this->translator->trans($grade->shortLabelKey()),
                        self::points($series[$grade->value], $scale),
                        $tone,
                    );
                }
                if ($prices !== []) {
                    $chart->addSeries(
                        $this->translator->trans('fuel_prices.chart.listed', [
                            'grade' => $this->translator->trans($grade->shortLabelKey()),
                        ]),
                        array_map(
                            static fn (DailyPrice $day): array => [$day->day, $scale->pricePerShownUnit((float) $day->close)],
                            $prices,
                        ),
                        $tone,
                        true,
                    );
                }
            }
            if (isset($series[''])) {
                $chart->addSeries(
                    $this->translator->trans('fuel.grade_not_recorded'),
                    self::points($series[''], $scale),
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
    private static function points(array $list, EconomyScale $scale): array
    {
        $points = [];
        foreach ($list as $point) {
            $points[] = [$point->filledAt, $scale->pricePerShownUnit((float) $point->price)];
        }

        return $points;
    }
}
