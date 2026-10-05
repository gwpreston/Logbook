<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;

/**
 * What a vehicle has lost (or gained) since it was bought (spec.md §7.1),
 * derived on every read and never stored, like its age.
 *
 * The current value is the sale price once sold, else the latest valuation.
 * The change needs only a purchase price; per year and per distance are
 * measured from the purchase date to the value's own date (not today: that
 * is when the value was true) and need both dates at least 90 days apart.
 * Per distance also needs the mileage series to reach back to the purchase:
 * otherwise the whole loss would be divided by part of the distance.
 * Nothing is extrapolated and amounts stay in the vehicle's currency.
 */
final readonly class Depreciation
{
    public const int MIN_DAYS = VehicleAge::MIN_DAYS_FOR_AVERAGE;
    /** A valuation older than this (unless sold) earns the stale hint: the default of the owner's setting (§7.24). */
    public const int STALE_MONTHS = 12;
    /** Average Gregorian month, for the part-month after whole months. */
    private const string DAYS_PER_MONTH = '30.436875';
    private const int MONEY_SCALE = 3;

    /**
     * @param list<ValuePoint> $points the value series in date order
     */
    private function __construct(
        public DepreciationState $state,
        public string $currency,
        public array $points,
        /** The sale, else the latest valuation; null without either. */
        public ?ValuePoint $current,
        /** Current value − purchase price, signed (canonical decimal, 3 places). */
        public ?string $change = null,
        /** The change as a fraction of the purchase price (6 places); null for a price of 0. */
        public ?string $fraction = null,
        /** The loss per year (3 places); null for a gain or under MIN_DAYS. */
        public ?string $perYear = null,
        /** The loss per kilometre driven (6 places); null for a gain, under MIN_DAYS or without distance. */
        public ?string $perKm = null,
        /** Whole months since the latest valuation, when it is stale. */
        public ?int $staleMonths = null,
    ) {
    }

    /**
     * @param list<VehicleValuation> $valuations the vehicle's, any order
     * @param list<OdometerReading> $readings the vehicle's mileage series, oldest first
     * @param DateTimeImmutable $today calendar date in the owner's time zone
     */
    public static function of(
        Vehicle $vehicle,
        array $valuations,
        array $readings,
        DateTimeImmutable $today,
        DateTimeZone $zone,
        string $currency,
        int $staleAfterMonths = self::STALE_MONTHS,
    ): self {
        $points = self::series($vehicle, $valuations);
        $current = self::current($points);
        $price = $vehicle->data->purchasePrice;

        if ($price === null) {
            return new self(DepreciationState::NoPurchasePrice, $currency, $points, $current);
        }
        if ($current === null) {
            return new self(DepreciationState::NoValue, $currency, $points, $current);
        }

        $change = Money::of($current->amount, $currency)->subtract(Money::of($price, $currency));
        $changeDecimal = $change->toDecimal(self::MONEY_SCALE);
        $fraction = Decimal::compare($price, '0') === 0 ? null : Decimal::divide($changeDecimal, $price, 6);

        $perYear = null;
        $perKm = null;
        $purchased = $vehicle->data->purchaseDate;
        if ($purchased !== null && LocalTime::daysBetween($purchased, $current->date) >= self::MIN_DAYS) {
            // The loss as a cost: a gain is negative (Phase 32, #154).
            $loss = Decimal::subtract('0', $changeDecimal);
            if ($change->isNegative()) {
                $months = self::monthsBetween($purchased, $current->date);
                $perYear = Decimal::divide(Decimal::multiply($loss, '12', 6), $months, self::MONEY_SCALE);
            }
            $km = PeriodDistance::reachesBack($readings, $purchased, $zone)
                ? PeriodDistance::km($readings, new ReportPeriod(ReportRange::Custom, $purchased, $current->date), $zone)
                : null;
            $perKm = $km === null ? null : Decimal::divide($loss, $km, 6);
        }

        return new self(
            DepreciationState::Ready,
            $currency,
            $points,
            $current,
            $changeDecimal,
            $fraction,
            $perYear,
            $perKm,
            $vehicle->data->saleDate === null && $current->kind === ValuePointKind::Valuation
                ? self::staleMonths($current->date, $today, $staleAfterMonths)
                : null,
        );
    }

    /**
     * The value series (spec.md §7.1): *Bought* (purchase date and price),
     * every valuation, *Sold* (sale date and price), in date order; on one
     * day the purchase first, the sale last and valuations in the order
     * they were added.
     *
     * @param list<VehicleValuation> $valuations
     * @return list<ValuePoint>
     */
    public static function series(Vehicle $vehicle, array $valuations): array
    {
        $data = $vehicle->data;
        $points = [];
        if ($data->purchaseDate !== null && $data->purchasePrice !== null) {
            $points[] = new ValuePoint(ValuePointKind::Bought, $data->purchaseDate, $data->purchasePrice);
        }
        foreach ($valuations as $valuation) {
            $points[] = new ValuePoint(
                ValuePointKind::Valuation,
                $valuation->data->valuedOn,
                $valuation->data->amount,
                $valuation->data->source,
                $valuation->id,
            );
        }
        if ($data->saleDate !== null && $data->salePrice !== null) {
            $points[] = new ValuePoint(ValuePointKind::Sold, $data->saleDate, $data->salePrice);
        }

        usort($points, static fn (ValuePoint $a, ValuePoint $b): int => [$a->date, $a->kind->rank(), $a->valuationId]
            <=> [$b->date, $b->kind->rank(), $b->valuationId]);

        return $points;
    }

    public function isGain(): bool
    {
        return $this->change !== null && Decimal::compare($this->change, '0') > 0;
    }

    public function isSold(): bool
    {
        return $this->current?->kind === ValuePointKind::Sold;
    }

    /**
     * @param list<ValuePoint> $points in date order
     */
    private static function current(array $points): ?ValuePoint
    {
        $latest = null;
        foreach ($points as $point) {
            if ($point->kind === ValuePointKind::Sold) {
                return $point;
            }
            if ($point->kind === ValuePointKind::Valuation) {
                $latest = $point;
            }
        }

        return $latest;
    }

    /**
     * Calendar months from one date to a later one: whole months (as the
     * vehicle's age counts them) plus the days left over as a fraction of an
     * average month, so 1 Mar 2023 → 1 Mar 2026 is exactly 36.
     */
    private static function monthsBetween(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $age = VehicleAge::between($from, $to);
        $whole = $age->years * 12 + $age->months;
        $days = LocalTime::daysBetween(LocalTime::addMonths($from, $whole), $to);

        return Decimal::add((string) $whole, Decimal::divide((string) $days, self::DAYS_PER_MONTH, 6));
    }

    /**
     * Whole months since a valuation once it is older than $after months
     * (spec.md §7.1 *Stale value*; the owner's setting from Phase 24,
     * §7.24), else null.
     *
     * @param DateTimeImmutable $today calendar date in the owner's time zone
     */
    public static function staleMonths(
        DateTimeImmutable $valuedOn,
        DateTimeImmutable $today,
        int $after = self::STALE_MONTHS,
    ): ?int {
        if (LocalTime::addMonths($valuedOn, $after) >= $today) {
            return null;
        }
        $age = VehicleAge::between($valuedOn, $today);

        return $age->years * 12 + $age->months;
    }
}
