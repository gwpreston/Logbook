<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Maintenance\DonePoint;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Reminder\ReminderRules;
use Logbook\Service\Maintenance\DueState;
use Logbook\Service\Maintenance\DueStatus;
use Logbook\Service\Maintenance\DueTrigger;
use Logbook\Service\Maintenance\ScheduleCalculator;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Currency;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;

/**
 * Builds *Coming up* (spec.md §7.18) from sources already loaded. Pure: no
 * database, no clock. Every due point comes from the calculations the
 * reminders use (ScheduleCalculator and DueState for schedules, the
 * documents' own expiry, the tyre judgement), so the forecast and a
 * reminder never disagree about a date.
 */
final class ForecastCalculator
{
    /** At most this many occurrences of one schedule or document. */
    public const int MAX_OCCURRENCES = 24;
    /** A document repeats only when its term is at least this long. */
    public const int MIN_TERM_DAYS = 28;

    /**
     * @param list<VehicleSources> $sources
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public static function forecast(array $sources, DateTimeImmutable $today): Forecast
    {
        $horizon = ForecastHorizon::from($today);
        $items = [];
        $fuel = [];
        foreach ($sources as $vehicle) {
            foreach ($vehicle->schedules as $schedule) {
                array_push($items, ...self::schedule($vehicle, $schedule, $horizon));
            }
            foreach ($vehicle->documents as $document) {
                array_push($items, ...self::document($vehicle, $document, $horizon));
            }
            foreach ($vehicle->tyres as $tyres) {
                $items[] = self::tyres($vehicle, $tyres);
            }
            if ($vehicle->firstInspection !== null) {
                $items[] = self::firstInspection($vehicle, $vehicle->firstInspection, $horizon);
            }
            foreach ($vehicle->reminders as $reminder) {
                $items[] = self::reminder($vehicle, $reminder, $horizon);
            }
            if ($vehicle->fuel !== null) {
                $fuel[] = self::fuel($vehicle, $vehicle->fuel, $horizon);
            }
        }
        $items = array_values(array_filter(
            $items,
            static fn (?ForecastItem $item): bool => $item !== null
                && ($item->overdue || $item->dueOn === null || $item->dueOn <= $horizon->end),
        ));
        usort($items, ForecastItem::compare(...));

        $overdue = [];
        $undated = [];
        $byMonth = array_fill(0, ForecastHorizon::MONTHS, []);
        foreach ($items as $item) {
            if ($item->overdue) {
                $overdue[] = $item;
            } elseif ($item->dueOn === null) {
                $undated[] = $item;
            } else {
                $byMonth[$horizon->monthIndex($item->dueOn) ?? 0][] = $item;
            }
        }
        $months = [];
        foreach ($horizon->months() as $i => $month) {
            $months[] = new ForecastMonth($month, $byMonth[$i]);
        }

        return new Forecast(
            $horizon,
            $overdue,
            $months,
            $undated,
            $fuel,
            self::totals($sources, $overdue, $months, $fuel),
        );
    }

    /**
     * The schedule's due point as its reminder has it, then repeats: each
     * assumes the work is done on the day it falls due, at the odometer
     * projected for that day, and adds the interval as logging it would.
     * Overdue: once, no repeats (past its distance limit, at the odometer it
     * was due at). Distance-only without a projection: once, undated.
     *
     * @return list<ForecastItem>
     */
    private static function schedule(VehicleSources $vehicle, ScheduleDue $due, ForecastHorizon $horizon): array
    {
        $schedule = $due->state->schedule;
        $state = $due->state->due;
        $item = static fn (?DateTimeImmutable $on, ?string $km, bool $projected, bool $overdue, int $n): ForecastItem
            => new ForecastItem(
                vehicle: $vehicle->vehicle,
                source: ForecastSource::Schedule,
                sourceId: $schedule->id,
                title: $schedule->data->title,
                category: $schedule->data->category->value,
                icon: $schedule->data->category->icon(),
                dueOn: $on,
                dueKm: $km,
                projected: $projected,
                overdue: $overdue,
                cost: $due->cost,
                currency: $vehicle->currency,
                occurrence: $n,
            );

        if ($state->status === DueStatus::Unknown) {
            return [];
        }
        if ($state->status === DueStatus::Overdue) {
            // Past its distance limit: the odometer it was due at, not a projected day.
            $byDistance = $state->trigger === DueTrigger::Distance;

            return [$item($byDistance ? null : $state->dueOn, $schedule->nextDue->km, false, true, 1)];
        }
        if ($state->dueOn === null) {
            return [$item(null, $schedule->nextDue->km, false, false, 1)];
        }

        $items = [];
        $on = $state->dueOn;
        $km = $schedule->nextDue->km;
        $projected = $state->projected;
        for ($n = 1; $n <= self::MAX_OCCURRENCES && $on <= $horizon->end; $n++) {
            $items[] = $item($on, $km, $projected, false, $n);

            $next = ScheduleCalculator::nextDue(
                new DonePoint($on, self::projectedKm($vehicle, $horizon->today, $on)),
                $schedule->data->intervalKm,
                $schedule->data->intervalMonths,
            );
            $following = DueState::evaluate($next, $horizon->today, $vehicle->currentKm, $vehicle->kmPerDay);
            if ($following->dueOn === null || $following->dueOn <= $on) {
                break;
            }
            $on = $following->dueOn;
            $km = $next->km;
            $projected = $following->projected;
        }

        return $items;
    }

    /**
     * The odometer on a future day at the average daily distance; null
     * without a reading or a projection.
     */
    private static function projectedKm(VehicleSources $vehicle, DateTimeImmutable $today, DateTimeImmutable $on): ?string
    {
        if ($vehicle->currentKm === null || $vehicle->kmPerDay === null) {
            return null;
        }
        $days = max(0, LocalTime::daysBetween($today, $on));

        return Decimal::add($vehicle->currentKm, Decimal::fromFloat($vehicle->kmPerDay * $days, 3));
    }

    /**
     * "Renew …" on the expiry date, then at the document's own term.
     *
     * @return list<ForecastItem>
     */
    private static function document(VehicleSources $vehicle, ComplianceDocument $document, ForecastHorizon $horizon): array
    {
        $data = $document->data;
        $expiry = $data->expiryOn;
        if ($expiry === null) {
            return [];
        }
        $item = static fn (DateTimeImmutable $on, bool $overdue, int $n): ForecastItem => new ForecastItem(
            vehicle: $vehicle->vehicle,
            source: ForecastSource::Document,
            sourceId: $document->id,
            title: $data->title !== null && $data->title !== '' ? $data->title : null,
            category: $data->type->value,
            icon: $data->type->icon(),
            dueOn: $on,
            dueKm: null,
            projected: false,
            overdue: $overdue,
            cost: $vehicle->costs ? self::positive($data->cost, $vehicle->currency) : null,
            currency: $vehicle->currency,
            occurrence: $n,
        );

        if ($expiry < $horizon->today) {
            return [$item($expiry, true, 1)];
        }

        $term = self::term($data->startOn, $expiry);
        $items = [];
        for ($n = 1; $n <= self::MAX_OCCURRENCES; $n++) {
            $on = match (true) {
                $n === 1 => $expiry,
                isset($term['months']) => LocalTime::addMonths($expiry, $term['months'] * ($n - 1)),
                isset($term['days']) => $expiry->modify(sprintf('+%d days', $term['days'] * ($n - 1))),
                default => null,
            };
            if ($on === null || $on > $horizon->end) {
                break;
            }
            $items[] = $item($on, false, $n);
        }

        return $items;
    }

    /**
     * A document's term: whole months when the start plus some months is
     * the expiry or the day after it (1 Jan to 31 Dec is 12 months), else
     * days; null without a start or under MIN_TERM_DAYS.
     *
     * @return array{months?: int, days?: int}|null
     */
    public static function term(?DateTimeImmutable $start, DateTimeImmutable $expiry): ?array
    {
        if ($start === null) {
            return null;
        }
        $days = LocalTime::daysBetween($start, $expiry);
        if ($days < self::MIN_TERM_DAYS) {
            return null;
        }

        $months = ((int) $expiry->format('Y') - (int) $start->format('Y')) * 12
            + (int) $expiry->format('n') - (int) $start->format('n');
        $dayAfter = $expiry->modify('+1 day');
        foreach ([$months, $months + 1] as $candidate) {
            if ($candidate <= 0) {
                continue;
            }
            $end = LocalTime::addMonths($start, $candidate);
            if ($end == $expiry || $end == $dayAfter) {
                return ['months' => $candidate];
            }
        }

        return ['days' => $days];
    }

    private static function tyres(VehicleSources $vehicle, TyreDue $tyres): ForecastItem
    {
        return new ForecastItem(
            vehicle: $vehicle->vehicle,
            source: ForecastSource::Tyres,
            sourceId: $vehicle->vehicle->id,
            title: $tyres->title,
            category: 'tyres',
            icon: 'tire_repair',
            dueOn: $tyres->dueOn,
            dueKm: $tyres->dueKm,
            projected: $tyres->projected && !$tyres->overdue,
            overdue: $tyres->overdue,
            cost: $tyres->cost,
            currency: $vehicle->currency,
        );
    }

    /**
     * The first MOT on its date: no repeats (later ones come from each
     * certificate) and no "last time" cost.
     */
    private static function firstInspection(
        VehicleSources $vehicle,
        DateTimeImmutable $on,
        ForecastHorizon $horizon,
    ): ForecastItem {
        return new ForecastItem(
            vehicle: $vehicle->vehicle,
            source: ForecastSource::FirstInspection,
            sourceId: $vehicle->vehicle->id,
            title: null,
            category: ComplianceType::Inspection->value,
            icon: ComplianceType::Inspection->icon(),
            dueOn: $on,
            dueKm: null,
            projected: false,
            overdue: $on < $horizon->today,
            cost: null,
            currency: $vehicle->currency,
        );
    }

    /**
     * A manual reminder: on its date, or, due at an odometer, on the sooner
     * of its date and the day the distance is projected to be reached
     * (spec.md §7.6); undated while an odometer-only one has no projection.
     */
    private static function reminder(VehicleSources $vehicle, Reminder $reminder, ForecastHorizon $horizon): ?ForecastItem
    {
        if ($reminder->dueOn === null && $reminder->dueKm === null) {
            return null;
        }
        $due = ReminderRules::manual(
            $reminder->dueOn,
            $reminder->dueKm,
            $horizon->today,
            $reminder->leadTimeDays,
            $vehicle->currentKm,
            $vehicle->kmPerDay,
        );

        return new ForecastItem(
            vehicle: $vehicle->vehicle,
            source: ForecastSource::Reminder,
            sourceId: $reminder->id,
            title: $reminder->title,
            category: null,
            icon: $reminder->icon(),
            dueOn: $due->on,
            dueKm: $reminder->dueKm,
            projected: $due->projected,
            overdue: $due->status === ReminderStatus::Overdue,
            cost: null,
            currency: $vehicle->currency,
        );
    }

    /**
     * Each month's projected distance × the fuel cost per distance, worked
     * in exact decimals and rounded once, to the currency's minor unit.
     */
    private static function fuel(VehicleSources $vehicle, FuelRate $rate, ForecastHorizon $horizon): FuelEstimate
    {
        if (!$rate->isReady() || $rate->perKm === null) {
            return new FuelEstimate($vehicle->vehicle, $vehicle->currency, $rate->status);
        }
        if ($vehicle->kmPerDay === null) {
            return new FuelEstimate($vehicle->vehicle, $vehicle->currency, FuelRateStatus::NotEnoughMileage);
        }

        $perDay = Decimal::fromFloat($vehicle->kmPerDay, 9);
        $digits = Currency::fractionDigits($vehicle->currency);
        $months = array_map(
            static fn (DateTimeImmutable $month): Money => Money::of(
                Decimal::multiply(
                    Decimal::multiply($perDay, (string) $horizon->daysIn($month), 9),
                    $rate->perKm,
                    $digits,
                ),
                $vehicle->currency,
            ),
            $horizon->months(),
        );

        return new FuelEstimate($vehicle->vehicle, $vehicle->currency, FuelRateStatus::Ready, $months, $rate->perKm);
    }

    /**
     * Per currency, per month: known planned costs (overdue items in this
     * month), the fuel estimates and the items without a known cost.
     * Undated items are in no total.
     *
     * @param list<VehicleSources> $sources
     * @param list<ForecastItem> $overdue
     * @param list<ForecastMonth> $months
     * @param list<FuelEstimate> $fuel
     * @return list<ForecastTotals>
     */
    private static function totals(array $sources, array $overdue, array $months, array $fuel): array
    {
        $currencies = array_values(array_unique(array_map(static fn (VehicleSources $s): string => $s->currency, $sources)));
        $totals = [];
        foreach ($currencies as $currency) {
            $estimates = array_values(array_filter($fuel, static fn (FuelEstimate $e): bool => $e->currency === $currency));
            $ready = array_values(array_filter($estimates, static fn (FuelEstimate $e): bool => $e->isReady()));
            $anyItem = false;
            $rows = [];
            foreach ($months as $i => $month) {
                $items = array_values(array_filter(
                    $i === 0 ? [...$overdue, ...$month->items] : $month->items,
                    static fn (ForecastItem $item): bool => $item->currency === $currency,
                ));
                $anyItem = $anyItem || $items !== [];
                $planned = Money::zero($currency);
                $unknown = 0;
                foreach ($items as $item) {
                    if ($item->cost === null) {
                        $unknown++;
                    } else {
                        $planned = $planned->add($item->cost);
                    }
                }
                $fuelTotal = Money::zero($currency);
                foreach ($ready as $estimate) {
                    $fuelTotal = $fuelTotal->add($estimate->months[$i]);
                }
                $rows[] = new ForecastMonthTotal($month->month, $planned, $fuelTotal, $unknown);
            }
            if (!$anyItem && $ready === []) {
                continue;
            }
            $totals[] = new ForecastTotals($currency, $rows, $ready !== [], count($estimates) - count($ready));
        }

        return $totals;
    }

    private static function positive(string $amount, string $currency): ?Money
    {
        $money = Money::of($amount, $currency);

        return $money->isZero() || $money->isNegative() ? null : $money;
    }

    /**
     * Last time's price: an amount above 0, else not known.
     */
    public static function cost(?string $amount, string $currency): ?Money
    {
        return $amount === null ? null : self::positive($amount, $currency);
    }
}
