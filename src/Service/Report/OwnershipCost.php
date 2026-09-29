<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\DepreciationState;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;

/**
 * What a vehicle has cost over the time it has been owned (spec.md §7.7
 * *Cost of ownership*): the running costs in the ownership period plus its
 * depreciation. Derived on every read, never stored, in the vehicle's
 * currency. Pure: no database and no clock.
 *
 * Rates add, each over its own period: running costs run to the end of the
 * ownership period, depreciation to the value's own date. A part that
 * cannot be worked out leaves the rate as the running part alone, flagged
 * as partial, never passed off as complete.
 */
final readonly class OwnershipCost
{
    public const int MIN_DAYS = VehicleAge::MIN_DAYS_FOR_AVERAGE;
    /** Places kept for money per km and per month. */
    private const int SCALE = 6;

    /**
     * @param list<GroupTotal> $groups every group, in CostGroup order
     */
    private function __construct(
        public Vehicle $vehicle,
        public string $currency,
        public ReportPeriod $period,
        public OwnershipStart $start,
        /** How long it has been owned, as the vehicle's age is written. */
        public VehicleAge $ownedFor,
        /** Calendar months the period touches (the current month counts), as reports count them. */
        public int $months,
        /** Kilometres driven in the period (canonical decimal), or null. */
        public ?string $distanceKm,
        public Money $running,
        public int $count,
        public array $groups,
        public Depreciation $depreciation,
        /** The loss as a cost (a gain is negative: money back); null without a price and a value. */
        public ?Money $depreciationCost,
        /** Running + depreciation; null without both. */
        public ?Money $total,
        public ?string $runningPerKm,
        public ?string $depreciationPerKm,
        /** The sum of both parts, or the running part alone (see perKmIsPartial). */
        public ?string $perKm,
        public bool $perKmIsPartial,
        public ?Money $runningPerMonth,
        public ?Money $depreciationPerMonth,
        public ?Money $perMonth,
        public bool $perMonthIsPartial,
        /** The owner's local day of the first reading, or null without one. */
        private ?DateTimeImmutable $mileageStart,
    ) {
    }

    /**
     * @param list<CostItem> $items the vehicle's ledger lines, any date, oldest first
     * @param list<OdometerReading> $readings the vehicle's mileage series, oldest first
     * @param DateTimeImmutable $today calendar date in the owner's time zone
     * @return self|null null when the period has no start (no purchase date,
     *                   nothing logged) or starts after it ends
     */
    public static function of(
        Vehicle $vehicle,
        array $items,
        array $readings,
        Depreciation $depreciation,
        DateTimeImmutable $today,
        DateTimeZone $zone,
    ): ?self {
        $currency = $depreciation->currency;
        [$from, $start] = self::start($vehicle, $items, $readings, $zone);
        $sale = $vehicle->data->saleDate;
        $to = $sale !== null && $sale < $today ? $sale : $today;
        if ($from === null || $from > $to) {
            return null;
        }
        $period = new ReportPeriod(ReportRange::Custom, $from, $to);

        $zero = Money::zero($currency);
        $running = $zero;
        $count = 0;
        /** @var array<string, Money> $byGroup */
        $byGroup = array_fill_keys(array_map(static fn (CostGroup $g): string => $g->value, CostGroup::cases()), $zero);
        foreach ($items as $item) {
            if ($item->vehicle->id !== $vehicle->id || !$period->contains($item->date)) {
                continue;
            }
            $running = $running->add($item->amount);
            $byGroup[$item->group()->value] = $byGroup[$item->group()->value]->add($item->amount);
            $count++;
        }
        $groups = array_map(
            static fn (CostGroup $g): GroupTotal => new GroupTotal(
                $g,
                $byGroup[$g->value],
                $running->micros === 0 ? 0.0 : $byGroup[$g->value]->micros / $running->micros * 100,
            ),
            CostGroup::cases(),
        );

        $depreciationCost = $depreciation->state === DepreciationState::Ready && $depreciation->change !== null
            ? $zero->subtract(Money::of($depreciation->change, $currency))
            : null;
        $total = $depreciationCost === null ? null : $running->add($depreciationCost);

        // Only when the mileage log reaches back to the start: otherwise the
        // whole period's costs would be divided by part of its distance.
        $km = PeriodDistance::reachesBack($readings, $from, $zone) ? PeriodDistance::km($readings, $period, $zone) : null;
        // Nothing logged is not "nothing spent": a rate of £0 would be made up.
        $hasRates = $count > 0 && LocalTime::daysBetween($from, $to) >= self::MIN_DAYS;
        $months = count($period->months());

        $runningPerKm = null;
        $depreciationPerKm = null;
        $perKm = null;
        $runningPerMonth = null;
        $depreciationPerMonth = null;
        $perMonth = null;
        if ($hasRates) {
            if ($km !== null) {
                $runningPerKm = Decimal::divide($running->toDecimal(Money::SCALE), $km, self::SCALE);
                $depreciationPerKm = $depreciationCost === null ? null : $depreciation->perKm;
                $perKm = $depreciationPerKm === null ? $runningPerKm : Decimal::add($runningPerKm, $depreciationPerKm);
            }
            $runningPerMonth = Money::of(
                Decimal::divide($running->toDecimal(Money::SCALE), (string) $months, self::SCALE),
                $currency,
            );
            $depreciationPerMonth = $depreciationCost === null || $depreciation->perYear === null
                ? null
                : Money::of(Decimal::divide($depreciation->perYear, '12', self::SCALE), $currency);
            $perMonth = $depreciationPerMonth === null ? $runningPerMonth : $runningPerMonth->add($depreciationPerMonth);
        }

        return new self(
            vehicle: $vehicle,
            currency: $currency,
            period: $period,
            start: $start,
            ownedFor: VehicleAge::between($from, $to),
            months: $months,
            distanceKm: $km,
            running: $running,
            count: $count,
            groups: $groups,
            depreciation: $depreciation,
            depreciationCost: $depreciationCost,
            total: $total,
            runningPerKm: $runningPerKm,
            depreciationPerKm: $depreciationPerKm,
            perKm: $perKm,
            perKmIsPartial: $perKm !== null && $depreciationPerKm === null,
            runningPerMonth: $runningPerMonth,
            depreciationPerMonth: $depreciationPerMonth,
            perMonth: $perMonth,
            perMonthIsPartial: $perMonth !== null && $depreciationPerMonth === null,
            mileageStart: $readings === [] ? null : LocalTime::dateOf($readings[0]->recordedAt, $zone),
        );
    }

    /**
     * Whether the total (and so the full rates) can be worked out: a
     * purchase price and a value.
     */
    public function isComplete(): bool
    {
        return $this->total !== null;
    }

    /**
     * A sale with a price ends both periods on the sale date: every figure
     * is exact.
     */
    public function isLifetime(): bool
    {
        return $this->isComplete() && $this->depreciation->isSold();
    }

    /**
     * Too short for rates to mean anything; the totals still show.
     */
    public function isTooShort(): bool
    {
        return $this->ownedFor->days < self::MIN_DAYS;
    }

    /**
     * The date depreciation is measured to (the value's), when there is one.
     */
    public function valuedOn(): ?DateTimeImmutable
    {
        return $this->isComplete() ? $this->depreciation->current?->date : null;
    }

    /**
     * The owner's local day of the first reading when the mileage log
     * starts after the period does (so there is no distance owned), for the
     * hint; null otherwise.
     */
    public function mileageStartsOn(): ?DateTimeImmutable
    {
        return $this->mileageStart !== null && $this->mileageStart > $this->period->from ? $this->mileageStart : null;
    }

    /**
     * The groups with something in them, for the card's lines.
     *
     * @return list<GroupTotal>
     */
    public function spentGroups(): array
    {
        return array_values(array_filter($this->groups, static fn (GroupTotal $g): bool => !$g->amount->isZero()));
    }

    /**
     * The start: the purchase date, else the earlier of the first ledger
     * line and the first reading (the owner's local day).
     *
     * @param list<CostItem> $items oldest first
     * @param list<OdometerReading> $readings oldest first
     * @return array{0: ?DateTimeImmutable, 1: OwnershipStart}
     */
    private static function start(Vehicle $vehicle, array $items, array $readings, DateTimeZone $zone): array
    {
        $purchased = $vehicle->data->purchaseDate;
        if ($purchased !== null) {
            return [$purchased, OwnershipStart::Purchase];
        }

        $first = null;
        foreach ($items as $item) {
            if ($item->vehicle->id === $vehicle->id) {
                $first = $item->date;
                break;
            }
        }
        if ($readings !== []) {
            $read = LocalTime::dateOf($readings[0]->recordedAt, $zone);
            $first = $first === null || $read < $first ? $read : $first;
        }

        return [$first, OwnershipStart::FirstLogged];
    }
}
