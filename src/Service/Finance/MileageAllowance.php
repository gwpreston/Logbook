<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * A PCP's or lease's mileage against its allowance (spec.md §7.32
 * *Mileage*):
 *
 * - the allowance over the whole agreement, annual × months ÷ 12, the
 *   months counted from the agreement date to the end date (#129);
 * - the distance so far, latest reading − start odometer (as entered, else
 *   the reading nearest the agreement date);
 * - the allowance used to date, pro rata by days;
 * - the projected distance at the end, the latest reading + the average
 *   daily distance (§7.4) × the days from it to the end date, and the
 *   excess and its charge when over. No projection without enough
 *   readings (a week of history) or once the agreement has ended: then the
 *   distance so far is the distance at the end.
 */
final class MileageAllowance
{
    /** Over by more than this share of the allowance raises *Needs attention* item 11. */
    public const float ATTENTION_PERCENT = 2.0;

    public static function of(
        FinanceAgreement $agreement,
        OdometerHistory $history,
        DateTimeImmutable $today,
        DateTimeZone $zone,
        string $currency,
    ): ?MileagePosition {
        $data = $agreement->data;
        if (!$data->type->hasMileage() || $data->annualMileageAllowance === null) {
            return null;
        }
        $unit = $data->mileageUnit;
        $endsOn = self::endsOn($agreement);
        $months = FinanceMath::monthsBetween($data->startedOn, $endsOn);
        $allowance = $unit->toKmDecimal(
            (string) BigDecimal::of($data->annualMileageAllowance)->multipliedBy($months)->dividedBy(12, 3, RoundingMode::HalfUp),
            3,
        );

        $totalDays = max(1, LocalTime::daysBetween($data->startedOn, $endsOn));
        $elapsed = min($totalDays, max(0, LocalTime::daysBetween($data->startedOn, $today)));
        $allowedToDate = Decimal::round((string) ((float) $allowance * $elapsed / $totalDays), 3);

        $start = $data->startOdometerKm ?? self::nearest($history, $data->startedOn, $zone)?->readingKm;
        $latest = $history->latest();
        $distance = null;
        if ($start !== null && $latest !== null) {
            $distance = Decimal::subtract($latest->readingKm, $start);
            if (Decimal::compare($distance, '0') < 0) {
                $distance = null;
            }
        }

        $projected = null;
        if ($distance !== null && $latest !== null) {
            if (!$agreement->status->isActive()) {
                $projected = $distance;
            } else {
                $perDay = $history->averageKmPerDay();
                if ($perDay !== null) {
                    $readOn = LocalTime::dateOf($latest->recordedAt, $zone);
                    $days = max(0, LocalTime::daysBetween($readOn, $endsOn));
                    $projected = Decimal::add($distance, Decimal::fromFloat($perDay * $days, 3));
                }
            }
        }

        $excess = $projected === null ? null : Decimal::subtract($projected, $allowance);
        $charge = null;
        if ($excess !== null && Decimal::compare($excess, '0') > 0 && $data->excessMileageCharge !== null) {
            $units = BigDecimal::of($unit->fromKmDecimal($excess, 3));
            $amount = $units->multipliedBy($data->excessMileageCharge)->toScale(0, RoundingMode::HalfUp);
            $charge = Money::of((string) $amount, $currency);
        }

        return new MileagePosition(
            unit: $unit,
            allowanceKm: $allowance,
            months: $months,
            endsOn: $endsOn,
            startKm: $start,
            distanceKm: $distance,
            allowedToDateKm: $allowedToDate,
            projectedKm: $projected,
            excessKm: $excess,
            excessCharge: $charge,
            chargePerUnit: $data->excessMileageCharge,
        );
    }

    /**
     * The day the car goes back: the final payment's date for PCP, a month
     * after the last rental for a lease (spec.md §7.32 *Mileage*).
     */
    public static function endsOn(FinanceAgreement $agreement): DateTimeImmutable
    {
        return Schedule::finalPaymentOn($agreement);
    }

    /** The reading closest to a date, the earlier one on a tie. */
    private static function nearest(OdometerHistory $history, DateTimeImmutable $on, DateTimeZone $zone): ?OdometerReading
    {
        $best = null;
        $bestDays = null;
        foreach ($history->readings as $reading) {
            $days = abs(LocalTime::daysBetween($on, LocalTime::dateOf($reading->recordedAt, $zone)));
            if ($bestDays === null || $days < $bestDays) {
                $best = $reading;
                $bestDays = $days;
            }
        }

        return $best;
    }
}
