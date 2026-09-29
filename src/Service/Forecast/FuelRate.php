<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeZone;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Expense\CostSource;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * A vehicle's fuel cost per kilometre over the reports' *last 12 months*
 * (spec.md §7.18): the fuel group's ledger spend ÷ the distance driven in
 * the same period (§7.7). A plug-in hybrid's petrol and electricity are
 * both fuel-group costs, so one rate covers both.
 */
final readonly class FuelRate
{
    /** The first fill-up in the period must be at least this many days before today. */
    public const int MIN_DAYS = 90;
    /** Places kept on the rate per km, so the estimate is rounded once at the end. */
    public const int SCALE = 12;

    public function __construct(
        public FuelRateStatus $status,
        /** Money per km (canonical decimal) when ready. */
        public ?string $perKm = null,
    ) {
    }

    /**
     * @param list<CostItem> $items the vehicle's ledger lines (any period)
     * @param list<OdometerReading> $readings the vehicle's mileage log, oldest first
     * @param ReportPeriod $period the reports' last 12 months, ending today
     */
    public static function of(array $items, array $readings, ReportPeriod $period, DateTimeZone $zone): self
    {
        $spend = '0';
        $firstFill = null;
        foreach ($items as $item) {
            if ($item->group() !== CostGroup::Fuel || !$period->contains($item->date)) {
                continue;
            }
            $spend = Decimal::add($spend, $item->amount->toDecimal(6));
            if ($item->source === CostSource::Fuel && ($firstFill === null || $item->date < $firstFill)) {
                $firstFill = $item->date;
            }
        }

        $km = PeriodDistance::km($readings, $period, $zone);
        if (
            $firstFill === null
            || $km === null
            || LocalTime::daysBetween($firstFill, $period->to) < self::MIN_DAYS
        ) {
            return new self(FuelRateStatus::NotEnoughFillUps);
        }

        return new self(FuelRateStatus::Ready, Decimal::divide($spend, $km, self::SCALE));
    }

    public function isReady(): bool
    {
        return $this->status === FuelRateStatus::Ready;
    }
}
