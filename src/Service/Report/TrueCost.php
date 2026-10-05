<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Expense\CostSource;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\CostItem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Apportion;
use Logbook\Support\Number\Decimal;

/**
 * A vehicle's true cost over one period (spec.md §7.35): what each part
 * came to and its cost per kilometre, the parts adding up exactly to the
 * total. Derived on every read, never stored, in the vehicle's currency.
 * Pure: no database and no clock.
 *
 * *Since bought* is §7.7's cost of ownership split into parts; the other
 * periods are worked out here by the same rules, with documents spread over
 * their cover and depreciation from the value curve.
 */
final readonly class TrueCost
{
    /** Under this many km a year is shown in the table only, too little to compare. */
    public const string MIN_COMPARE_KM = '500';
    /** Places kept for money per km, as cost of ownership keeps them. */
    public const int SCALE = 6;

    /**
     * @param array<string, Money> $amounts the running parts by TruePart value
     * @param list<EnergyUse> $energies fill-ups by kind of energy
     * @param array<string, string> $rates per km by TruePart value; depreciation only when known
     */
    private function __construct(
        public Vehicle $vehicle,
        public string $currency,
        public TrueCostPeriod $period,
        public array $amounts,
        /** Insurance payouts received in the period (positive), taken off the total. */
        public Money $payouts,
        /** The loss as a cost (a gain is negative); null when there is none to measure. */
        public ?Money $depreciation,
        /** The last day depreciation is measured to, when that is before the period ends. */
        public ?DateTimeImmutable $depreciationTo,
        /** Kilometres driven in the period (canonical decimal), or null. */
        public ?string $distanceKm,
        /** Kilometres driven over the days depreciation is measured, or null. */
        public ?string $depreciationKm,
        public array $energies,
        public array $rates,
        public ?string $payoutsPerKm,
        /** The parts' rates added up; null with the reason in $gap. */
        public ?string $perKm,
        public ?TrueCostGap $gap,
    ) {
    }

    /**
     * *Since bought*: cost of ownership (§7.7) split into its parts. Its
     * running rate is shared out between the groups and the payouts, so the
     * parts add up exactly to the card's figure.
     *
     * @param list<CostItem> $items the vehicle's ledger lines, any date
     */
    public static function sinceBought(OwnershipCost $cost, array $items): self
    {
        $currency = $cost->currency;
        $period = new TrueCostPeriod(TrueCostRange::SinceBought, $cost->period->from ?? $cost->period->to, $cost->period->to);
        $amounts = [];
        foreach ($cost->groups as $group) {
            $amounts[TruePart::ofGroup($group->group)->value] = $group->amount;
        }
        $payouts = $cost->payouts ?? Money::zero($currency);

        $rates = [];
        $payoutsPerKm = null;
        if ($cost->runningPerKm !== null && $cost->distanceKm !== null) {
            [$rates, $payoutsPerKm] = self::runningRates($amounts, $payouts, $cost->distanceKm, $cost->runningPerKm);
            if ($cost->depreciationPerKm !== null) {
                $rates[TruePart::Depreciation->value] = $cost->depreciationPerKm;
            }
        }

        $gap = null;
        if ($cost->perKm === null) {
            $gap = match (true) {
                $cost->isTooShort() => TrueCostGap::TooShort,
                $cost->count === 0 => TrueCostGap::NoCosts,
                default => TrueCostGap::NoMileage,
            };
        }

        return new self(
            vehicle: $cost->vehicle,
            currency: $currency,
            period: $period,
            amounts: $amounts,
            payouts: $payouts,
            depreciation: $cost->depreciationCost,
            depreciationTo: $cost->isComplete() && !$cost->isLifetime() ? $cost->valuedOn() : null,
            distanceKm: $cost->distanceKm,
            depreciationKm: null,
            energies: self::energies($cost->vehicle, $items, $period, $currency),
            rates: $rates,
            payoutsPerKm: $payoutsPerKm,
            perKm: $cost->perKm,
            gap: $gap,
        );
    }

    /**
     * *Last 12 months*, the 12 before them or a calendar year.
     *
     * @param list<CostItem> $items the vehicle's ledger lines, any date
     * @param list<OdometerReading> $readings the vehicle's mileage series, oldest first
     * @param list<InsurancePayout> $payouts the vehicle's, any date
     * @param int $minDays fewer days than this gives no rates (as cost of ownership's 90 for 12 months)
     */
    public static function forPeriod(
        Vehicle $vehicle,
        string $currency,
        TrueCostPeriod $period,
        array $items,
        array $readings,
        ValueCurve $curve,
        array $payouts,
        DateTimeZone $zone,
        int $minDays = 0,
    ): self {
        $zero = Money::zero($currency);
        $amounts = array_fill_keys(array_map(static fn (TruePart $p): string => $p->value, TruePart::running()), $zero);
        $count = 0;
        foreach ($items as $item) {
            if ($item->vehicle->id !== $vehicle->id) {
                continue;
            }
            $share = self::share($item, $period);
            if ($share === null) {
                continue;
            }
            $part = TruePart::ofGroup($item->group())->value;
            $amounts[$part] = $amounts[$part]->add($share);
            $count++;
        }

        $received = $zero;
        foreach ($payouts as $payout) {
            // A total loss's settlement is the sale price (§7.29), as in cost of ownership.
            if ($vehicle->isWrittenOff() && $payout->incidentId === $vehicle->disposalIncidentId) {
                continue;
            }
            if ($period->contains($payout->date)) {
                $received = $received->add($payout->amount);
            }
        }

        $depreciation = $curve->depreciation($period->from, $period->to, $currency);
        $km = PeriodDistance::reachesBack($readings, $period->from, $zone)
            ? PeriodDistance::km($readings, $period->report(), $zone)
            : null;
        $depreciationKm = null;
        if ($depreciation !== null && $km !== null) {
            $depreciationKm = $depreciation->cutAtStart || $depreciation->cutAtEnd
                ? PeriodDistance::km($readings, new ReportPeriod(ReportRange::Custom, $depreciation->from, $depreciation->to), $zone)
                : $km;
        }

        $rates = [];
        $payoutsPerKm = null;
        $perKm = null;
        $gap = null;
        if ($period->days() < $minDays) {
            $gap = TrueCostGap::TooShort;
        } elseif ($count === 0) {
            $gap = TrueCostGap::NoCosts;
        } elseif ($km === null) {
            $gap = TrueCostGap::NoMileage;
        } else {
            $net = $zero;
            foreach ($amounts as $amount) {
                $net = $net->add($amount);
            }
            $net = $net->subtract($received);
            $runningPerKm = self::rational($net)->dividedBy(BigRational::of($km))
                ->toScale(self::SCALE, RoundingMode::HalfUp)->toString();
            [$rates, $payoutsPerKm] = self::runningRates($amounts, $received, $km, $runningPerKm);
            $perKm = $runningPerKm;
            if ($depreciation !== null && $depreciationKm !== null) {
                $rate = self::rational($depreciation->amount)->dividedBy(BigRational::of($depreciationKm))
                    ->toScale(self::SCALE, RoundingMode::HalfUp)->toString();
                $rates[TruePart::Depreciation->value] = $rate;
                $perKm = Decimal::add($perKm, $rate);
            }
        }

        return new self(
            vehicle: $vehicle,
            currency: $currency,
            period: $period,
            amounts: $amounts,
            payouts: $received,
            depreciation: $depreciation?->amount,
            depreciationTo: $depreciation !== null && $depreciation->cutAtEnd ? $depreciation->to : null,
            distanceKm: $km,
            depreciationKm: $depreciationKm,
            energies: self::energies($vehicle, $items, $period, $currency),
            rates: $rates,
            payoutsPerKm: $payoutsPerKm,
            perKm: $perKm,
            gap: $gap,
        );
    }

    public function amount(TruePart $part): ?Money
    {
        return $part === TruePart::Depreciation ? $this->depreciation : $this->amounts[$part->value] ?? null;
    }

    public function rate(TruePart $part): ?string
    {
        return $this->rates[$part->value] ?? null;
    }

    /**
     * Running costs net of payouts.
     */
    public function running(): Money
    {
        $sum = Money::zero($this->currency);
        foreach ($this->amounts as $amount) {
            $sum = $sum->add($amount);
        }

        return $sum->subtract($this->payouts);
    }

    /**
     * Running costs plus depreciation; the running costs alone without it.
     */
    public function total(): Money
    {
        return $this->depreciation === null ? $this->running() : $this->running()->add($this->depreciation);
    }

    /**
     * The rate leaves depreciation out: "running costs only".
     */
    public function isRunningOnly(): bool
    {
        return $this->perKm !== null && !isset($this->rates[TruePart::Depreciation->value]);
    }

    /**
     * Enough distance for its rate to be compared with another period's.
     */
    public function isComparable(): bool
    {
        return $this->perKm !== null
            && $this->distanceKm !== null
            && Decimal::compare($this->distanceKm, self::MIN_COMPARE_KM) >= 0;
    }

    /**
     * The parts with a rate, for the list: depreciation last, a part with
     * nothing in it left out.
     *
     * @return list<TruePart>
     */
    public function parts(): array
    {
        return array_values(array_filter(
            TruePart::cases(),
            fn (TruePart $p): bool => isset($this->rates[$p->value]) && Decimal::compare($this->rates[$p->value], '0') !== 0,
        ));
    }

    /**
     * The stacked bar: each positive part's share of the positive parts,
     * in percent. Negative parts (a gain, payouts) are listed, not drawn.
     *
     * @return list<array{part: TruePart, percent: float}>
     */
    public function bar(): array
    {
        $positive = 0.0;
        foreach ($this->rates as $rate) {
            $positive += max(0.0, (float) $rate);
        }
        if ($positive <= 0.0) {
            return [];
        }
        $bar = [];
        foreach ($this->parts() as $part) {
            $rate = (float) $this->rates[$part->value];
            if ($rate > 0.0) {
                $bar[] = ['part' => $part, 'percent' => round($rate / $positive * 100, 2)];
            }
        }

        return $bar;
    }

    /**
     * The running parts' rates and the payouts' (negative), shared out so
     * they add up exactly to the running rate.
     *
     * @param array<string, Money> $amounts
     * @return array{0: array<string, string>, 1: ?string}
     */
    private static function runningRates(array $amounts, Money $payouts, string $km, string $runningPerKm): array
    {
        $distance = BigRational::of($km);
        $keys = [];
        $parts = [];
        foreach (TruePart::running() as $part) {
            $keys[] = $part->value;
            $parts[] = self::rational($amounts[$part->value] ?? Money::zero($payouts->currency))->dividedBy($distance);
        }
        $hasPayouts = !$payouts->isZero();
        if ($hasPayouts) {
            $keys[] = 'payouts';
            $parts[] = self::rational($payouts)->negated()->dividedBy($distance);
        }
        $shared = array_combine($keys, Apportion::toTarget($parts, $runningPerKm, self::SCALE));
        $payoutsPerKm = $hasPayouts ? $shared['payouts'] : null;
        unset($shared['payouts']);

        return [$shared, $payoutsPerKm];
    }

    /**
     * What a ledger line adds to a period: the whole of it when dated in it,
     * or for a document with a cover (§7.35, #153; not *Since bought*,
     * which keeps the ledger's dates) the days of cover in the period. The
     * shares are cumulative floors, so a document's shares over adjacent
     * periods add up exactly to its cost.
     */
    private static function share(CostItem $item, TrueCostPeriod $period): ?Money
    {
        $cover = $item->source === CostSource::Compliance ? $item->coverTo : null;
        if ($cover === null || $period->range === TrueCostRange::SinceBought) {
            return $period->contains($item->date) ? $item->amount : null;
        }
        if ($period->to < $item->date || $period->from > $cover) {
            return null;
        }
        $days = LocalTime::daysBetween($item->date, $cover) + 1;
        $from = $period->from > $item->date ? $period->from : $item->date;
        $to = $period->to < $cover ? $period->to : $cover;
        $before = LocalTime::daysBetween($item->date, $from);
        $through = LocalTime::daysBetween($item->date, $to) + 1;
        $micros = $item->amount->micros;
        $share = self::floorDiv($micros * $through, $days) - self::floorDiv($micros * $before, $days);

        return Money::of(Decimal::fromScaledInt($share, Money::SCALE), $item->amount->currency);
    }

    private static function floorDiv(int $a, int $b): int
    {
        return intdiv($a, $b) - ((($a % $b) !== 0 && (($a < 0) !== ($b < 0))) ? 1 : 0);
    }

    /**
     * @param list<CostItem> $items
     * @return list<EnergyUse>
     */
    private static function energies(Vehicle $vehicle, array $items, TrueCostPeriod $period, string $currency): array
    {
        /** @var array<string, EnergyUse> $byKind */
        $byKind = [];
        foreach ($items as $item) {
            if (
                $item->vehicle->id !== $vehicle->id
                || $item->group() !== CostGroup::Fuel
                || $item->fuel === null
                || !$period->contains($item->date)
            ) {
                continue;
            }
            $kind = $item->fuel->kind();
            $was = $byKind[$kind->value] ?? new EnergyUse($kind, Money::zero($currency), '0');
            $byKind[$kind->value] = new EnergyUse(
                $kind,
                $was->cost->add($item->amount),
                Decimal::add($was->units, $item->quantity ?? '0'),
            );
        }

        return array_values($byKind);
    }

    private static function rational(Money $money): BigRational
    {
        return BigRational::of($money->toDecimal(Money::SCALE));
    }
}
