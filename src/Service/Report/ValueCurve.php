<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use DateInterval;
use DateTimeImmutable;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\ValuePoint;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;

/**
 * A vehicle's value on any day between its value points (spec.md §7.1
 * *Depreciation for a period*, §7.35): the purchase, each valuation and the
 * sale, joined by straight lines by day. Nothing is extrapolated: before the
 * first point and after the latest there is no value. Pure: no database and
 * no clock.
 *
 * A value point is the value at the start of its day, so adjacent periods
 * (31 Dec, then 1 Jan) share their boundary and lose nothing between them.
 */
final readonly class ValueCurve
{
    /**
     * @param list<ValuePoint> $points one per day (the last of a day counts), in date order
     */
    private function __construct(public array $points)
    {
    }

    /**
     * Without a purchase price there is no depreciation at all (§7.1): a
     * leased car's rentals are its cost.
     *
     * @param list<VehicleValuation> $valuations
     */
    public static function of(Vehicle $vehicle, array $valuations): self
    {
        if ($vehicle->data->purchasePrice === null) {
            return new self([]);
        }

        return self::fromPoints(Depreciation::series($vehicle, $valuations));
    }

    /**
     * @param list<ValuePoint> $points in date order
     */
    public static function fromPoints(array $points): self
    {
        $byDay = [];
        foreach ($points as $point) {
            $byDay[$point->date->format('Y-m-d')] = $point;
        }

        return new self(array_values($byDay));
    }

    public function first(): ?ValuePoint
    {
        return $this->points[0] ?? null;
    }

    public function latest(): ?ValuePoint
    {
        return $this->points === [] ? null : $this->points[count($this->points) - 1];
    }

    /**
     * The value at the start of $day, interpolated; null outside the points.
     */
    public function valueOn(DateTimeImmutable $day): ?BigRational
    {
        $before = null;
        foreach ($this->points as $point) {
            if ($point->date == $day) {
                return BigRational::of($point->amount);
            }
            if ($point->date > $day) {
                if ($before === null) {
                    return null;
                }
                $span = LocalTime::daysBetween($before->date, $point->date);
                $into = LocalTime::daysBetween($before->date, $day);
                $from = BigRational::of($before->amount);

                return $from->plus(BigRational::of($point->amount)->minus($from)->multipliedBy($into)->dividedBy($span));
            }
            $before = $point;
        }

        return null;
    }

    /**
     * What the vehicle lost over a period of calendar days (both inclusive):
     * the value at the start of $from minus the value at the end of $to,
     * cut to the value points. Null when the period lies wholly outside
     * them (or touches them only on one day).
     */
    public function depreciation(DateTimeImmutable $from, DateTimeImmutable $to, string $currency): ?PeriodDepreciation
    {
        $first = $this->first();
        $latest = $this->latest();
        if ($first === null || $latest === null) {
            return null;
        }
        $end = $to->add(new DateInterval('P1D'));
        $start = $from < $first->date ? $first->date : $from;
        $cutAtEnd = $end > $latest->date;
        $stop = $cutAtEnd ? $latest->date : $end;
        if ($start >= $stop) {
            return null;
        }
        $startValue = $this->valueOn($start);
        $stopValue = $this->valueOn($stop);
        assert($startValue !== null && $stopValue !== null);
        $loss = $startValue->minus($stopValue)->toScale(Money::SCALE, RoundingMode::HalfUp)->toString();

        return new PeriodDepreciation(
            Money::of($loss, $currency),
            $start,
            // The last day counted: the latest point's day itself when cut there.
            $cutAtEnd ? $latest->date : $to,
            $start > $from,
            $cutAtEnd,
        );
    }
}
