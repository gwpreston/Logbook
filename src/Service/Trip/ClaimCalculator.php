<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Trip\TaxYear;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Support\Money\Currency;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * Values a claimant's business trips at their dated mileage rates (spec.md
 * §7.23). Pure: callers pass the claimant's own business trips, from the
 * start of the earliest tax year they need, and get every one back valued.
 *
 * - Each trip uses the rate set in effect on its date (the latest
 *   `effective_from` on or before it), in that set's unit and currency.
 * - Car trips count towards the set's threshold across all the claimant's
 *   cars, in date order and then the order logged, restarting each tax
 *   year. The crossing trip is split. A rate change mid-year keeps the
 *   year's running total.
 * - Bikes use the bike rate (or the car rate) with no threshold, and do not
 *   count towards the car threshold.
 * - Passengers are passengers × distance × passenger rate.
 * - Every amount is rounded to the currency's minor unit per trip.
 */
final class ClaimCalculator
{
    /** Distances in a rate set's unit. */
    public const int DISTANCE_SCALE = 3;
    /**
     * Products before rounding to the minor unit: distance (3 places) ×
     * rate (4) is exact at 7, and × passengers stays exact, so rounding to
     * the minor unit is the only rounding.
     */
    private const int WORK_SCALE = 8;

    /**
     * @param list<Trip> $trips the claimant's business trips
     * @param array<int, VehicleType> $vehicleTypes by vehicle id
     * @param list<MileageRateSet> $rateSets the claimant's, any order
     */
    public function value(array $trips, array $vehicleTypes, array $rateSets, string $taxYearStart): ClaimValuation
    {
        $trips = self::inClaimOrder($trips);
        $rateSets = self::byEffectiveDate($rateSets);

        /** @var array<int, array{unit: DistanceUnit, used: string}> $carDistance by tax year's start year */
        $carDistance = [];
        $valued = [];
        foreach ($trips as $trip) {
            $set = self::inEffect($rateSets, $trip->data->travelledOn);
            if ($set === null) {
                $valued[] = new ValuedTrip($trip);
                continue;
            }

            $rates = $set->data;
            $unit = $rates->distanceUnit;
            $distance = $unit->fromKmDecimal($trip->data->distanceKm, self::DISTANCE_SCALE);
            $isBike = ($vehicleTypes[$trip->vehicleId] ?? VehicleType::Car) === VehicleType::Bike;

            if ($isBike) {
                $lines = [new ClaimLine($distance, $rates->bikeRate ?? $rates->carRate)];
            } else {
                $year = TaxYear::containing($trip->data->travelledOn, $taxYearStart)->startYear();
                $used = self::usedIn($carDistance[$year] ?? null, $unit);
                $lines = self::carLines($distance, $used, $rates->carRate, $rates->carThreshold, $rates->carRateAfter);
                $carDistance[$year] = ['unit' => $unit, 'used' => Decimal::add($used, $distance)];
            }

            $digits = Currency::fractionDigits($rates->currency);
            $mileage = '0';
            foreach ($lines as $line) {
                $mileage = Decimal::add($mileage, Decimal::multiply($line->distance, $line->rate, self::WORK_SCALE));
            }

            $passengerAmount = null;
            if ($trip->data->passengers > 0 && $rates->passengerRate !== null) {
                $passengerAmount = Decimal::round(Decimal::multiply(
                    Decimal::multiply($distance, (string) $trip->data->passengers, self::WORK_SCALE),
                    $rates->passengerRate,
                    self::WORK_SCALE,
                ), $digits);
            }

            $employerAmount = null;
            if ($rates->hasEmployerRates()) {
                // A bike falls back to the employer's car rate, as bike_rate falls back to car_rate.
                $employerRate = ($isBike ? $rates->employerBikeRate ?? $rates->employerCarRate : $rates->employerCarRate) ?? '0';
                $employerAmount = Decimal::round(Decimal::multiply($distance, $employerRate, self::WORK_SCALE), $digits);
            }

            $valued[] = new ValuedTrip(
                trip: $trip,
                rateSet: $set,
                distance: $distance,
                lines: $lines,
                mileageAmount: Decimal::round($mileage, $digits),
                passengerRate: $passengerAmount === null ? null : $rates->passengerRate,
                passengerAmount: $passengerAmount,
                employerAmount: $employerAmount,
            );
        }

        return new ClaimValuation($valued, $carDistance, self::byEffectiveDate($rateSets), $taxYearStart);
    }

    /**
     * @param list<Trip> $trips
     * @return list<Trip>
     */
    public static function inClaimOrder(array $trips): array
    {
        usort($trips, static fn (Trip $a, Trip $b): int => [$a->data->travelledOn, $a->createdAt, $a->id]
            <=> [$b->data->travelledOn, $b->createdAt, $b->id]);

        return $trips;
    }

    /**
     * @param list<MileageRateSet> $rateSets
     * @return list<MileageRateSet> oldest first
     */
    public static function byEffectiveDate(array $rateSets): array
    {
        usort(
            $rateSets,
            static fn (MileageRateSet $a, MileageRateSet $b): int => $a->data->effectiveFrom <=> $b->data->effectiveFrom,
        );

        return $rateSets;
    }

    /**
     * @param list<MileageRateSet> $rateSets oldest first
     */
    public static function inEffect(array $rateSets, DateTimeImmutable $on): ?MileageRateSet
    {
        $found = null;
        foreach ($rateSets as $set) {
            if ($set->data->effectiveFrom > $on) {
                break;
            }
            $found = $set;
        }

        return $found;
    }

    /**
     * The year's car distance so far in $unit. A year's total is kept in
     * the unit of the set that last added to it and converted only when
     * the unit changes, so a year of trips typed in miles adds up exactly.
     *
     * @param array{unit: DistanceUnit, used: string}|null $total
     */
    public static function usedIn(?array $total, DistanceUnit $unit): string
    {
        if ($total === null) {
            return '0';
        }
        if ($total['unit'] === $unit) {
            return $total['used'];
        }

        return $unit->fromKmDecimal($total['unit']->toKmDecimal($total['used'], self::WORK_SCALE), self::DISTANCE_SCALE);
    }

    /**
     * @return list<ClaimLine>
     */
    private static function carLines(string $distance, string $used, string $rate, ?string $threshold, ?string $rateAfter): array
    {
        if ($threshold === null || $rateAfter === null) {
            return [new ClaimLine($distance, $rate)];
        }

        $left = Decimal::subtract($threshold, $used);
        if (Decimal::compare($left, '0') <= 0) {
            return [new ClaimLine($distance, $rateAfter)];
        }
        if (Decimal::compare($distance, $left) <= 0) {
            return [new ClaimLine($distance, $rate)];
        }

        return [
            new ClaimLine(Decimal::round($left, self::DISTANCE_SCALE), $rate),
            new ClaimLine(Decimal::round(Decimal::subtract($distance, $left), self::DISTANCE_SCALE), $rateAfter),
        ];
    }
}
