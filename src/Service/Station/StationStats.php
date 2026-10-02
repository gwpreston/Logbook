<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Logbook\Repository\FuelEntryRepository;

/**
 * What a user paid at stations, from their own fill-ups (spec.md §7.33):
 * visits and the last visit over every fill-up they can see, and per grade
 * and currency the volume, spend, average price (weighted by volume),
 * cheapest and last price, over the fill-ups whose amounts they may see.
 *
 * Pure: callers pass the visits already filtered to the vehicles the user
 * can see. Sums are exact decimals (brick/math), never floats.
 */
final class StationStats
{
    /**
     * @param list<StationVisit> $visits
     * @param DateTimeImmutable|null $since only fill-ups from this instant (UTC); null = all
     * @return array<int, StationSummary> keyed by station id
     */
    public static function summarise(array $visits, ?DateTimeImmutable $since = null): array
    {
        /** @var array<int, list<StationVisit>> $byStation */
        $byStation = [];
        foreach ($visits as $visit) {
            if ($visit->entry->data->stationId === null) {
                continue;
            }
            if ($since !== null && $visit->entry->data->filledAt < $since) {
                continue;
            }
            $byStation[$visit->stationId()][] = $visit;
        }

        $summaries = [];
        foreach ($byStation as $stationId => $stationVisits) {
            $summaries[$stationId] = self::station($stationId, $stationVisits);
        }

        return $summaries;
    }

    /**
     * @param list<StationVisit> $visits
     */
    private static function station(int $stationId, array $visits): StationSummary
    {
        usort($visits, static fn (StationVisit $a, StationVisit $b): int => [$a->entry->data->filledAt, $a->entry->id]
            <=> [$b->entry->data->filledAt, $b->entry->id]);

        $groups = [];
        $history = [];
        foreach ($visits as $visit) {
            $data = $visit->entry->data;
            $key = ($data->grade === null ? $data->fuel->value : $data->fuel->value . ':' . $data->grade->value)
                . '|' . $visit->currency;
            $groups[$key][] = $visit;
            if ($visit->amountVisible) {
                $history[] = new PricePoint(
                    $data->filledAt,
                    $data->fuel,
                    $data->grade,
                    $visit->currency,
                    $data->pricePerUnit,
                    $visit->entry->vehicleId,
                );
            }
        }

        $grades = array_map(self::grade(...), array_values($groups));
        usort($grades, static fn (GradeStats $a, GradeStats $b): int => BigDecimal::of($b->volume)->compareTo($a->volume)
            ?: [$b->visits, $a->key(), $a->currency] <=> [$a->visits, $b->key(), $b->currency]);

        $last = $visits[count($visits) - 1] ?? null;

        return new StationSummary($stationId, count($visits), $last?->entry->data->filledAt, $grades, $history);
    }

    /**
     * @param non-empty-list<StationVisit> $visits oldest first, one fuel, grade and currency
     */
    private static function grade(array $visits): GradeStats
    {
        $first = $visits[0];
        $volume = BigDecimal::zero();
        $weighted = BigDecimal::zero();
        $spend = BigDecimal::zero();
        $cheapest = null;
        $cheapestOn = null;
        $lastPrice = null;
        $lastOn = null;
        foreach ($visits as $visit) {
            if (!$visit->amountVisible) {
                continue;
            }
            $data = $visit->entry->data;
            $price = BigDecimal::of($data->pricePerUnit);
            $volume = $volume->plus($data->volume);
            $weighted = $weighted->plus($price->multipliedBy($data->volume));
            $spend = $spend->plus($data->totalCost);
            if ($cheapest === null || $price->isLessThan($cheapest)) {
                $cheapest = $price;
                $cheapestOn = $data->filledAt;
            }
            $lastPrice = $price;
            $lastOn = $data->filledAt;
        }

        $scale = FuelEntryRepository::PRICE_SCALE;

        return new GradeStats(
            fuel: $first->entry->data->fuel,
            grade: $first->entry->data->grade,
            currency: $first->currency,
            visits: count($visits),
            volume: (string) $volume->toScale(FuelEntryRepository::QUANTITY_SCALE, RoundingMode::HalfUp),
            spend: (string) $spend->toScale(FuelEntryRepository::MONEY_SCALE, RoundingMode::HalfUp),
            averagePrice: $volume->isZero() ? null : (string) $weighted->dividedBy($volume, $scale, RoundingMode::HalfUp),
            cheapestPrice: $cheapest === null ? null : (string) $cheapest->toScale($scale, RoundingMode::HalfUp),
            cheapestOn: $cheapestOn,
            lastPrice: $lastPrice === null ? null : (string) $lastPrice->toScale($scale, RoundingMode::HalfUp),
            lastOn: $lastOn,
        );
    }
}
