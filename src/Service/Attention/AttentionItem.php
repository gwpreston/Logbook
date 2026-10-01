<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Service\Incident\IncidentView;
use DateTimeImmutable;
use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\Attention\AttentionSeverity;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Odometer\OdometerWarning;

/**
 * One thing wrong with a vehicle right now (spec.md §7.24), for one user:
 * what it is, what it is about, and which of its actions they may take.
 * Derived on every read (AttentionList), never stored. Worded by
 * AttentionWording.
 */
final readonly class AttentionItem
{
    public function __construct(
        public AttentionKind $kind,
        public Vehicle $vehicle,
        /** The reading's, fill-up's or record's id for those kinds, the vehicle's otherwise (or the forecast item's source id). */
        public int $subjectId,
        public string $icon,
        /** The *Coming up* item (Overdue). */
        public ?ForecastItem $forecast = null,
        /** Whole days past its date (Overdue with a date in the past). */
        public ?int $days = null,
        /** Its current open reminder (Overdue, reminders on): *Dismiss*, or *Done* for a manual one. */
        public ?int $reminderId = null,
        public ?OdometerReading $reading = null,
        public ?OdometerWarning $warning = null,
        /** How many fill-ups are flagged (Economy). */
        public int $count = 0,
        /** The latest reading (MileageStale; null: none at all). */
        public ?OdometerReading $latest = null,
        /** When the latest valuation was made (ValuationStale). */
        public ?DateTimeImmutable $valuedOn = null,
        /** Whole months since then (ValuationStale). */
        public ?int $months = null,
        /** The drift and its likely causes (DriftLiquid, DriftElectric). */
        public ?DriftFinding $drift = null,
        /** The fill-up and the price it was compared with (FuelPrice). */
        public ?PriceFinding $price = null,
        /** The record and the cost it was compared with (MaintenanceCost). */
        public ?CostFinding $cost = null,
        /** The incident whose claim is waiting (StalledClaim), seen with its details. */
        public ?IncidentView $incident = null,
        /** The vehicle's currency, for the amounts in a price or cost title. */
        public ?string $currency = null,
        /** What was judged (hideable kinds). */
        public ?string $fingerprint = null,
        /** May take the main action: *Log it*, *Fix*, *Review*, *Add reading*, *Add valuation*, *View economy*. */
        public bool $canAct = false,
        /** May dismiss (or, for a manual reminder, mark done) its reminder. */
        public bool $canDismiss = false,
        /** May hide it. */
        public bool $canHide = false,
    ) {
    }

    public function severity(): AttentionSeverity
    {
        return $this->kind->severity();
    }

    /**
     * Now before Check; overdue work oldest first (as *Coming up* orders
     * it), then by kind, readings, fill-ups and records oldest first.
     */
    public static function compare(self $a, self $b): int
    {
        if ($a->forecast !== null && $b->forecast !== null) {
            return ForecastItem::compare($a->forecast, $b->forecast);
        }

        return ($a->kind->rank() <=> $b->kind->rank())
            ?: (($a->reading?->recordedAt <=> $b->reading?->recordedAt))
            ?: (($a->price?->entry->data->filledAt <=> $b->price?->entry->data->filledAt))
            ?: (($a->cost?->entry->data->performedOn <=> $b->cost?->entry->data->performedOn))
            ?: strcmp($a->vehicle->name(), $b->vehicle->name())
            ?: ($a->vehicle->id <=> $b->vehicle->id)
            ?: ($a->subjectId <=> $b->subjectId);
    }
}
