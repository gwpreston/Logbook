<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Station;
use Logbook\Service\Station\GradeStats;
use Logbook\Service\Station\StationSummary;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Service\Incident\ClaimsRow;
use Logbook\Service\Incident\IncidentView;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\FuelPrices\PriceAlert;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Tyre\TyreLineAction;
use Logbook\Domain\Tyre\TyreSet;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\ScheduledPayment;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Fuel\EconomySegment;
use Logbook\Service\Fuel\EconomySummary;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\SegmentCheck;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimLine;
use Logbook\Service\Trip\ClaimTotals;
use Logbook\Service\Trip\ValuedTrip;
use Logbook\Service\Tyre\TyreStanding;
use Logbook\Service\Tyre\TyreView;
use Logbook\Service\Maintenance\ScheduleState;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;

/**
 * Typed domain objects → the API's JSON shapes (spec.md §7.20,
 * docs/api/openapi.json). Pure: callers pass what the policy decided.
 *
 * - Quantities are canonical decimal strings (km, litres or kWh,
 *   L/100 km or kWh/100 km), money is in the vehicle's currency, each
 *   object names its units once.
 * - Instants are ISO 8601 UTC with a Z, calendar dates YYYY-MM-DD.
 * - With `$costs` false (no ViewCosts), amount fields are left out, never
 *   zeroed.
 */
final class Serializer
{
    public const string DISTANCE_UNIT = 'km';
    public const string DEPTH_UNIT = 'mm';
    /** Places for derived consumption figures (stored ones keep their own). */
    public const int CONSUMPTION_SCALE = 3;
    /** Places for money per kilometre. */
    public const int PER_KM_SCALE = 4;
    /** Places for km, litres, kWh, mm and money: the stored precision. */
    public const int QUANTITY_SCALE = 3;
    /** Places for a price per litre (kWh), as stored. */
    public const int PRICE_SCALE = 6;
    /** Places for a mileage rate per km or mile, as stored. */
    public const int RATE_SCALE = 4;

    public static function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null
            ? null
            : DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public static function vehicle(Vehicle $vehicle, string $currency, bool $costs): array
    {
        $data = $vehicle->data;
        $out = [
            'id' => $vehicle->id,
            'name' => $vehicle->name(),
            'type' => $data->type->value,
            'make' => $data->make,
            'model' => $data->model,
            'variant' => $data->variant,
            'nickname' => $data->nickname,
            'year' => $data->year,
            'registration' => $data->registration,
            'vin' => $data->vin,
            'fuel_type' => $data->fuelType->value,
            'default_grade' => $data->defaultGrade?->value,
            'capacity' => self::dec($data->capacity, self::QUANTITY_SCALE),
            'capacity_unit' => self::volumeUnit($data->fuelType->primaryKind()),
            'first_registered_on' => self::date($data->firstRegisteredOn),
            'first_inspection_due_on' => self::date($data->firstInspectionDueOn),
            'purchase_date' => self::date($data->purchaseDate),
            'purchase_seller' => $data->purchaseSeller,
            'sale_date' => self::date($data->saleDate),
            'currency' => $currency,
            'currency_override' => $data->currency,
            'status' => $vehicle->status->value,
            // Phase 39.2: how it left (a sale date marks it sold; archive sets the rest).
            'disposal' => $vehicle->disposal?->value,
            'archived_at' => self::instant($vehicle->archivedAt),
            'has_photo' => $vehicle->hasPhoto(),
            'created_at' => self::instant($vehicle->createdAt),
            'updated_at' => self::instant($vehicle->updatedAt),
        ];
        if ($costs) {
            $out['purchase_price'] = self::dec($data->purchasePrice, self::QUANTITY_SCALE);
            $out['sale_price'] = self::dec($data->salePrice, self::QUANTITY_SCALE);
        }

        return $out;
    }

    /**
     * A fill-up with what the fuel history knows about it.
     *
     * @return array<string, mixed>
     */
    public static function fuelEntry(
        FuelEntry $entry,
        ?FillEconomy $fill,
        ?SegmentCheck $check,
        string $currency,
        bool $costs,
        bool $ownAmount = false,
    ): array {
        $data = $entry->data;
        $kind = $data->fuel->kind();
        $out = [
            'id' => $entry->id,
            'vehicle_id' => $entry->vehicleId,
            'filled_at' => self::instant($data->filledAt),
            'odometer' => self::dec($data->odometerKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'fuel' => $data->fuel->value,
            'grade' => $data->grade?->value,
            'energy' => $kind->value,
            'volume' => self::dec($data->volume, self::QUANTITY_SCALE),
            'volume_unit' => self::volumeUnit($kind),
            'is_partial' => $data->isPartial,
            'is_missed_previous' => $data->isMissedPrevious,
            'station' => $data->station,
            'station_id' => $data->stationId,
            'notes' => $data->notes,
            'economy' => $fill === null ? null : self::economy($fill, $kind, $costs),
            'economy_check' => $check === null ? null : [
                'verdict' => $check->verdict->value,
                'flagged' => $check->isFlagged(),
                'confirmed' => $check->confirmed,
            ],
            'created_at' => self::instant($entry->createdAt),
            'updated_at' => self::instant($entry->updatedAt),
        ];
        // The fill-up's own amounts also for whoever added it (spec.md §7.21); the segment's cost is ViewCosts only.
        if ($costs || $ownAmount) {
            $out['currency'] = $currency;
            $out['price_per_unit'] = self::dec($data->pricePerUnit, self::PRICE_SCALE);
            $out['total_cost'] = self::dec($data->totalCost, self::QUANTITY_SCALE);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function economy(FillEconomy $fill, EnergyKind $kind, bool $costs): array
    {
        return [
            'status' => $fill->status->value,
            'distance_since_previous' => self::dec($fill->distanceSincePreviousKm, self::QUANTITY_SCALE),
            'consumption_unit' => self::consumptionUnit($kind),
            'segment' => $fill->segment === null ? null : self::segment($fill->segment, $costs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function segment(EconomySegment $segment, bool $costs): array
    {
        $out = [
            'distance' => self::dec($segment->distanceKm, self::QUANTITY_SCALE),
            'volume' => self::dec($segment->volume, self::QUANTITY_SCALE),
            'consumption' => self::per100Km($segment->volume, $segment->distanceKm),
            'fills' => $segment->fills,
            'grade' => $segment->grade?->value,
        ];
        if ($costs) {
            $out['cost'] = self::dec($segment->cost, self::QUANTITY_SCALE);
        }

        return $out;
    }

    /**
     * One series' average economy (spec.md §7.3), for the summary.
     *
     * @return array<string, mixed>
     */
    public static function economySummary(EconomySummary $summary, bool $costs): array
    {
        $last = $summary->lastSegment;
        $out = [
            'fills' => $summary->fills,
            'segments' => $summary->segments,
            'consumption_unit' => self::consumptionUnit($summary->kind),
            'volume_unit' => self::volumeUnit($summary->kind),
            'average_consumption' => $summary->hasEconomy()
                ? self::per100Km($summary->measuredVolume, $summary->measuredDistanceKm)
                : null,
            'last_consumption' => $last === null ? null : self::per100Km($last->volume, $last->distanceKm),
            'measured_distance' => self::dec($summary->measuredDistanceKm, self::QUANTITY_SCALE),
            'total_volume' => self::dec($summary->totalVolume, self::QUANTITY_SCALE),
        ];
        if ($costs) {
            $perUnit = $summary->averagePricePerUnit();
            $perKm = $summary->costPerKm();
            $out['total_cost'] = self::dec($summary->totalCost, self::QUANTITY_SCALE);
            $out['average_price_per_unit'] = $perUnit === null ? null : Decimal::round($perUnit, 3);
            $out['cost_per_distance'] = $perKm === null ? null : Decimal::round($perKm, self::PER_KM_SCALE);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function odometerReading(OdometerReading $reading): array
    {
        return [
            'id' => $reading->id,
            'vehicle_id' => $reading->vehicleId,
            'recorded_at' => self::instant($reading->recordedAt),
            'odometer' => self::dec($reading->readingKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'source' => $reading->source->value,
            'source_id' => match ($reading->source) {
                // A manual reading has no owner; the purchase reading's is the vehicle itself.
                OdometerSource::Manual, OdometerSource::Purchase => null,
                OdometerSource::Fuel => $reading->fuelEntryId,
                OdometerSource::Maintenance => $reading->maintenanceEntryId,
                OdometerSource::Document => $reading->complianceDocumentId,
                OdometerSource::Tyre => $reading->tyreChangeId,
                OdometerSource::Incident => $reading->incidentId,
            },
            'note' => $reading->note,
            'created_at' => self::instant($reading->createdAt),
            'updated_at' => self::instant($reading->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function maintenanceEntry(MaintenanceEntry $entry, string $currency, bool $costs): array
    {
        $data = $entry->data;
        $out = [
            'id' => $entry->id,
            'vehicle_id' => $entry->vehicleId,
            'performed_on' => self::date($data->performedOn),
            'category' => $data->category->value,
            'title' => $data->title,
            'odometer' => self::dec($data->odometerKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'vendor' => $data->vendor,
            'description' => $data->description,
            'schedule_id' => $data->scheduleId,
            'created_at' => self::instant($entry->createdAt),
            'updated_at' => self::instant($entry->updatedAt),
        ];
        if ($costs) {
            $out['currency'] = $currency;
            $out['cost'] = self::dec($data->cost, self::QUANTITY_SCALE);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(DocumentState $state, string $currency, bool $costs): array
    {
        $document = $state->document;
        $data = $document->data;
        $out = [
            'id' => $document->id,
            'vehicle_id' => $document->vehicleId,
            'type' => $data->type->value,
            'title' => $data->title,
            'provider' => $data->provider,
            'reference' => $data->reference,
            'start_on' => self::date($data->startOn),
            'expiry_on' => self::date($data->expiryOn),
            'status' => $state->status->value,
            'days_left' => $state->daysLeft,
            'odometer' => self::dec($data->odometerKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'notes' => $data->notes,
            'created_at' => self::instant($document->createdAt),
            'updated_at' => self::instant($document->updatedAt),
        ];
        if ($costs) {
            $out['currency'] = $currency;
            $out['cost'] = self::dec($data->cost, self::QUANTITY_SCALE);
        }

        return $out;
    }

    /**
     * A current document's expiry, for the summary.
     *
     * @return array<string, mixed>
     */
    public static function documentExpiry(DocumentState $state): array
    {
        $document = $state->document;

        return [
            'id' => $document->id,
            'type' => $document->data->type->value,
            'title' => $document->data->title,
            'expiry_on' => self::date($document->data->expiryOn),
            'status' => $state->status->value,
            'days_left' => $state->daysLeft,
        ];
    }

    /**
     * A tyre change (spec.md §7.17, §7.20 Phase 39) with its lines. A line's
     * `position` is where the tyre went for `on` and `move`, and where it was
     * for `off`, `retire` and `repair`; `retire_reason` is the tyre's, on a
     * `retire` line.
     *
     * @param array<int, Tyre> $tyres the vehicle's tyres by id
     * @return array<string, mixed>
     */
    public static function tyreChange(TyreChange $change, array $tyres): array
    {
        $data = $change->data;

        return [
            'id' => $change->id,
            'vehicle_id' => $change->vehicleId,
            'kind' => $change->kind->value,
            'changed_on' => self::date($data->doneOn),
            'odometer' => self::dec($data->odometerKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'note' => $data->note,
            'service_record_id' => $data->maintenanceEntryId,
            'incident_id' => $change->incidentId,
            'lines' => array_map(static fn (TyreChangeLine $line): array => [
                'tyre_id' => $line->tyreId,
                'action' => $line->action->value,
                'position' => $line->position?->value,
                'depth_mm' => self::dec($line->treadMm, 3),
                'retire_reason' => $line->action === TyreLineAction::Retire
                    ? ($tyres[$line->tyreId] ?? null)?->retiredReason?->value
                    : null,
            ], $change->lines),
            'created_by' => $change->createdBy,
            'created_at' => self::instant($change->createdAt),
            'updated_at' => self::instant($change->updatedAt),
        ];
    }

    /**
     * A tyre set (spec.md §7.17) with the tyres in it.
     *
     * @param list<Tyre> $tyres the set's tyres
     * @return array<string, mixed>
     */
    public static function tyreSet(TyreSet $set, array $tyres): array
    {
        return [
            'id' => $set->id,
            'vehicle_id' => $set->vehicleId,
            'name' => $set->data->name,
            'storage_location' => $set->data->storageLocation,
            'notes' => $set->data->notes,
            'tyres' => array_map(static fn (Tyre $tyre): array => [
                'id' => $tyre->id,
                'status' => $tyre->status->value,
                'position' => $tyre->position?->value,
            ], $tyres),
            'created_at' => self::instant($set->createdAt),
            'updated_at' => self::instant($set->updatedAt),
        ];
    }

    /**
     * A tread check (Phase 26.3): the depth measured at each position, mm.
     *
     * @return array<string, mixed>
     */
    public static function treadCheck(TyreChange $check): array
    {
        $data = $check->data;

        return [
            'id' => $check->id,
            'vehicle_id' => $check->vehicleId,
            'checked_on' => self::date($data->doneOn),
            'odometer' => self::dec($data->odometerKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'note' => $data->note,
            'depths' => array_map(static fn (TyreChangeLine $line): array => [
                'position' => $line->position?->value,
                'tyre_id' => $line->tyreId,
                'depth_mm' => self::dec($line->treadMm, 3),
            ], $check->lines),
            'created_at' => self::instant($check->createdAt),
            'updated_at' => self::instant($check->updatedAt),
        ];
    }

    /**
     * Expenses are only listed with ViewCosts, so the amount is always there.
     *
     * @return array<string, mixed>
     */
    public static function expense(ExpenseEntry $entry, string $currency): array
    {
        $data = $entry->data;

        return [
            'id' => $entry->id,
            'vehicle_id' => $entry->vehicleId,
            'spent_on' => self::date($data->spentOn),
            'category' => $data->category->value,
            'amount' => self::dec($data->amount, self::QUANTITY_SCALE),
            'currency' => $currency,
            'note' => $data->note,
            'created_at' => self::instant($entry->createdAt),
            'updated_at' => self::instant($entry->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tyre(TyreView $view, ?TyreStanding $standing, string $currency, bool $costs): array
    {
        $tyre = $view->tyre;
        $wear = $view->wear;
        $latest = $wear->latest;
        $out = [
            'id' => $tyre->id,
            'vehicle_id' => $tyre->vehicleId,
            'name' => $tyre->data->name(),
            'brand' => $tyre->data->brand,
            'model' => $tyre->data->model,
            'size' => $tyre->data->size,
            'season' => $tyre->data->season?->value,
            'dot' => $tyre->data->dot?->code,
            'manufactured_on' => self::date($tyre->data->dot?->manufacturedOn),
            'status' => $tyre->status->value,
            'position' => $tyre->position?->value,
            'set_id' => $tyre->setId,
            'retired_reason' => $tyre->retiredReason?->value,
            'since' => self::date($view->since),
            'retired_on' => self::date($view->retiredOn),
            'depth_unit' => self::DEPTH_UNIT,
            'distance_unit' => self::DISTANCE_UNIT,
            'tread' => [
                'latest' => $latest === null ? null : [
                    'measured_on' => self::date($latest->doneOn),
                    'depth' => self::dec($latest->treadMm, self::QUANTITY_SCALE),
                    'distance' => self::dec($latest->distanceKm, self::QUANTITY_SCALE),
                ],
                'depth_now' => self::dec($wear->depthNowMm, self::QUANTITY_SCALE),
                'replace_at' => self::dec($wear->replaceAtMm, self::QUANTITY_SCALE),
                'distance_left' => self::dec($wear->kmLeft, self::QUANTITY_SCALE),
                'worn' => $wear->worn,
            ],
            'due' => $standing === null ? null : [
                'status' => $standing->status->value,
                'reason' => $standing->reason,
                'due_on' => self::date($standing->dueOn),
                'due_odometer' => self::dec($standing->dueKm, self::QUANTITY_SCALE),
            ],
            'notes' => $tyre->data->notes,
            'created_at' => self::instant($tyre->createdAt),
            'updated_at' => self::instant($tyre->updatedAt),
        ];
        if ($costs) {
            $out['currency'] = $currency;
            $out['cost_per_distance'] = $view->costPerKm === null ? null : Decimal::round($view->costPerKm, self::PER_KM_SCALE);
        }

        return $out;
    }

    /**
     * A *Coming up* item (spec.md §7.18).
     *
     * @return array<string, mixed>
     */
    public static function upcoming(ForecastItem $item, bool $costs): array
    {
        $out = [
            'vehicle_id' => $item->vehicle->id,
            'source' => $item->source->value,
            // A plain finance line (below Manage, spec.md §7.32 *Coming up*) names no agreement.
            'source_id' => $item->finance?->plain === true ? null : $item->sourceId,
            'title' => $item->title,
            'category' => $item->category,
            'due_on' => self::date($item->dueOn),
            'due_odometer' => self::dec($item->dueKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'projected' => $item->projected,
            'overdue' => $item->overdue,
            'occurrence' => $item->occurrence,
        ];
        if ($costs) {
            $out['currency'] = $item->currency;
            $out['cost'] = $item->cost === null ? null : $item->cost->toDecimal(3);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function reminder(ReminderEntry $entry, DateTimeImmutable $today): array
    {
        $reminder = $entry->reminder;

        return [
            'id' => $reminder->id,
            'vehicle_id' => $reminder->vehicleId,
            'source' => $reminder->source->value,
            'title' => $reminder->title,
            'category' => $reminder->category,
            'notes' => $reminder->notes,
            'status' => $reminder->status->value,
            'due_on' => self::date($reminder->dueOn),
            'due_odometer' => self::dec($reminder->dueKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'days_left' => $entry->daysLeft($today),
            'lead_time_days' => $reminder->leadTimeDays,
            // Phase 39.1: when it was marked done or dismissed; null while open.
            'closed_at' => $reminder->closedAt === null ? null : self::instant($reminder->closedAt),
            'created_at' => self::instant($reminder->createdAt),
            'updated_at' => self::instant($reminder->updatedAt),
        ];
    }

    /**
     * A trip (spec.md §7.22): distances in km, the whole trip's.
     * `created_by` is its driver (null: a former user).
     *
     * @return array<string, mixed>
     */
    public static function trip(Trip $trip): array
    {
        $data = $trip->data;

        return [
            'id' => $trip->id,
            'vehicle_id' => $trip->vehicleId,
            'travelled_on' => self::date($data->travelledOn),
            'from' => $data->fromPlace,
            'to' => $data->toPlace,
            'journey' => $trip->journey(),
            'is_return' => $data->isReturn,
            'distance_km' => self::dec($data->distanceKm, self::QUANTITY_SCALE),
            'odometer_start_km' => self::dec($data->odometerStartKm, self::QUANTITY_SCALE),
            'odometer_end_km' => self::dec($data->odometerEndKm, self::QUANTITY_SCALE),
            'is_business' => $data->isBusiness,
            'purpose' => $data->purpose,
            'passengers' => $data->passengers,
            'notes' => $data->notes,
            'created_by' => $trip->createdBy,
            'created_at' => self::instant($trip->createdAt),
            'updated_at' => self::instant($trip->updatedAt),
        ];
    }

    /**
     * An incident as the key's user may see it (spec.md §7.29 *Access*):
     * the summary always; the detail fields only with `details` (null
     * otherwise, `claim` and `other_party` null); amounts also need
     * `amounts`. Links are the linked records' ids.
     *
     * @param array<string, list<int>> $links by LinkKind value
     * @return array<string, mixed>
     */
    public static function incident(
        IncidentView $view,
        ?string $driver,
        ?string $odometerKm,
        string $currency,
        array $links,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
    ): array {
        $costs = $view->costs;

        return [
            'id' => $view->id,
            'vehicle_id' => $view->vehicleId,
            'occurred_on' => self::date($view->occurredOn),
            'type' => $view->type->value,
            'damage_areas' => array_map(static fn (DamageArea $area): string => $area->value, $view->damageAreas),
            'severity' => $view->severity?->value,
            'write_off_category' => $view->writeOff->value,
            'status' => $view->status->value,
            'closed_on' => $view->closedOn === null ? null : self::date($view->closedOn),
            'odometer' => self::dec($odometerKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'details' => $view->details,
            'occurred_at_time' => $view->occurredAtTime,
            'location' => $view->location,
            'fault' => $view->fault?->value,
            'description' => $view->description,
            'driver_user_id' => $view->driverUserId,
            'driver' => $view->details ? $driver : null,
            'police_reference' => $view->policeReference,
            'notes' => $view->notes,
            'other_party' => $view->details ? [
                'name' => $view->otherPartyName,
                'registration' => $view->otherPartyRegistration,
                'insurer' => $view->otherPartyInsurer,
            ] : null,
            'claim' => $view->details ? [
                'status' => $view->claimStatus?->value,
                'insurer' => $view->insurer,
                'insurance_document_id' => $view->insuranceDocumentId,
                'claim_number' => $view->claimNumber,
                'excess' => self::dec($view->excess, self::QUANTITY_SCALE),
                'payout' => self::dec($view->payout, self::QUANTITY_SCALE),
                'repair_estimate' => self::dec($view->repairEstimate, self::QUANTITY_SCALE),
                'ncd_affected' => $view->ncdAffected?->value,
                'updated_on' => $view->claimUpdatedOn === null ? null : self::date($view->claimUpdatedOn),
            ] : null,
            'costs' => $costs === null ? null : [
                'linked' => $costs->linked->toDecimal(2),
                'payouts' => $view->showsPayouts() ? $costs->payouts->toDecimal(2) : null,
                'net' => $view->showsPayouts() ? $costs->net->toDecimal(2) : null,
                'received_more' => $view->showsPayouts() ? $costs->receivedMore : null,
            ],
            'currency' => $currency,
            'links' => [
                'maintenance' => $links['maintenance'] ?? [],
                'expenses' => $links['expense'] ?? [],
                'tyre_changes' => $links['tyre'] ?? [],
            ],
            'created_by' => $view->createdBy,
            'created_at' => $createdAt === null ? null : self::instant($createdAt),
            'updated_at' => $updatedAt === null ? null : self::instant($updatedAt),
        ];
    }

    /**
     * A claims history row (spec.md §7.29): never the other party.
     *
     * @return array<string, mixed>
     */
    public static function claimsRow(ClaimsRow $row): array
    {
        $view = $row->incident;
        $vehicle = $row->vehicle;

        return [
            'incident_id' => $view->id,
            'occurred_on' => self::date($view->occurredOn),
            'vehicle' => [
                'id' => $vehicle->id,
                'name' => $vehicle->name(),
                'registration' => $vehicle->data->registration,
                'archived' => $vehicle->isArchived(),
            ],
            'type' => $view->type->value,
            'write_off_category' => $view->writeOff->value,
            'details' => $view->details,
            'fault' => $view->fault?->value,
            'driver' => $row->driver,
            'claim_status' => $view->claimStatus?->value,
            'insurer' => $view->insurer,
            'claim_number' => $view->claimNumber,
            'payout' => self::dec($view->payout, self::QUANTITY_SCALE),
            'currency' => $row->currency,
            'ncd_affected' => $view->ncdAffected?->value,
        ];
    }

    /**
     * A price alert (spec.md §7.34): per litre, in the provider's currency.
     *
     * @return array<string, mixed>
     */
    public static function priceAlert(PriceAlert $alert, string $currency): array
    {
        return [
            'id' => $alert->id,
            'station_id' => $alert->stationId,
            'grade' => $alert->grade->value,
            'below' => self::dec($alert->below, self::QUANTITY_SCALE),
            'volume_unit' => 'l',
            'currency' => $currency,
            'armed' => $alert->isArmed(),
            'triggered_at' => self::instant($alert->triggeredAt),
            'created_at' => self::instant($alert->createdAt),
            'updated_at' => self::instant($alert->updatedAt),
        ];
    }

    /**
     * A saved journey (spec.md §7.20 `GET /journeys`): one way, in km.
     *
     * @return array<string, mixed>
     */
    public static function savedJourney(SavedJourney $journey): array
    {
        $data = $journey->data;

        return [
            'id' => $journey->id,
            'from' => $data->fromPlace,
            'to' => $data->toPlace,
            'journey' => $journey->journey(),
            'distance_km' => self::dec($data->distanceKm, self::QUANTITY_SCALE),
            'is_return' => $data->isReturnDefault,
            'is_business' => $data->isBusinessDefault,
            'purpose' => $data->purposeDefault,
        ];
    }

    /**
     * A station and what the key's user paid there (spec.md §7.33 *API*):
     * from the fill-ups on the vehicles they can see, amounts only where
     * they may see them. Never their places.
     *
     * @param list<array<string, mixed>>|null $listed its listed prices (Phase 30.2), or null
     * @return array<string, mixed>
     */
    public static function station(Station $station, ?StationSummary $summary, bool $favourite, ?array $listed = null): array
    {
        $data = $station->data;

        return [
            'id' => $station->id,
            'name' => $data->name,
            'brand' => $data->brand,
            'address' => $data->address,
            'postcode' => $data->postcode,
            'country' => $data->country,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'grades' => array_map(static fn (FuelGrade $grade): string => $grade->value, $data->grades),
            'opening_hours' => $data->openingHours,
            'notes' => $data->notes,
            'favourite' => $favourite,
            'visits' => $summary->visits ?? 0,
            'last_visit' => self::instant($summary?->lastVisit),
            'paid' => $summary === null ? [] : array_map(static fn (GradeStats $stats): array => [
                'fuel' => $stats->fuel->value,
                'grade' => $stats->grade?->value,
                'currency' => $stats->currency,
                'visits' => $stats->visits,
                'volume' => self::dec($stats->volume, self::QUANTITY_SCALE),
                'spend' => self::dec($stats->spend, self::QUANTITY_SCALE),
                'average_price' => self::dec($stats->averagePrice, self::PRICE_SCALE),
                'cheapest_price' => self::dec($stats->cheapestPrice, self::PRICE_SCALE),
                'cheapest_at' => self::instant($stats->cheapestOn),
                'last_price' => self::dec($stats->lastPrice, self::PRICE_SCALE),
                'last_at' => self::instant($stats->lastOn),
            ], $summary->grades),
            // Phase 30.2: a linked station's listed prices while a provider is enabled (spec.md §7.34).
            'listed' => $listed,
            'created_at' => self::instant($station->createdAt),
            'updated_at' => self::instant($station->updatedAt),
        ];
    }

    /**
     * What a claim report covers (spec.md §7.23).
     *
     * @return array<string, mixed>
     */
    public static function claimPeriod(ClaimFilter $filter): array
    {
        return [
            'from' => self::date($filter->from),
            'to' => self::date($filter->to),
            'tax_year' => $filter->taxYear?->label(),
        ];
    }

    /**
     * One row of a claim: the trip at its rates, in the rate set's unit
     * and currency; all null (no lines) when no rate set was in effect.
     *
     * @return array<string, mixed>
     */
    public static function claimTrip(ValuedTrip $row): array
    {
        $data = $row->trip->data;

        return [
            'id' => $row->trip->id,
            'vehicle_id' => $row->trip->vehicleId,
            'travelled_on' => self::date($data->travelledOn),
            'journey' => $row->trip->journey(),
            'purpose' => $data->purpose,
            'distance_km' => self::dec($data->distanceKm, self::QUANTITY_SCALE),
            'distance' => self::dec($row->distance, self::QUANTITY_SCALE),
            'unit' => $row->unit()?->value,
            'passengers' => $data->passengers,
            'lines' => array_map(static fn (ClaimLine $line): array => [
                'distance' => self::dec($line->distance, self::QUANTITY_SCALE),
                'rate' => self::dec($line->rate, self::RATE_SCALE),
            ], $row->lines),
            'passenger_amount' => self::dec($row->passengerAmount, self::QUANTITY_SCALE),
            'amount' => self::dec($row->amount(), self::QUANTITY_SCALE),
            'currency' => $row->currency(),
        ];
    }

    /**
     * One currency's claim totals.
     *
     * @return array<string, mixed>
     */
    public static function claimTotals(ClaimTotals $totals): array
    {
        return [
            'currency' => $totals->currency,
            'rates' => array_map(static fn (array $rate): array => [
                'unit' => $rate['unit']->value,
                'rate' => self::dec($rate['rate'], self::RATE_SCALE),
                'distance' => self::dec($rate['distance'], self::QUANTITY_SCALE),
                'amount' => self::dec($rate['amount'], self::QUANTITY_SCALE),
            ], $totals->rates),
            'mileage_amount' => self::dec($totals->mileageAmount, self::QUANTITY_SCALE),
            'passenger_amount' => self::dec($totals->passengerAmount, self::QUANTITY_SCALE),
            'approved_amount' => self::dec($totals->approvedAmount(), self::QUANTITY_SCALE),
            'employer_amount' => self::dec($totals->employerAmount, self::QUANTITY_SCALE),
            'difference' => self::dec($totals->difference(), self::QUANTITY_SCALE),
        ];
    }

    /**
     * A finance agreement's summary and schedule (spec.md §7.20 *Finance*,
     * §7.32): the same figures as its page, every estimate marked as one,
     * never the agreement number. Amounts in the vehicle's currency;
     * distances in km, with the agreement's own unit for the allowance and
     * its excess charge.
     *
     * @return array<string, mixed>
     */
    public static function financeAgreement(AgreementView $view): array
    {
        $agreement = $view->agreement;
        $data = $agreement->data;
        $figures = $view->figures;
        $schedule = $figures->schedule;
        $money = static fn (?Money $amount): ?string => $amount?->toDecimal(self::QUANTITY_SCALE);
        $settlement = $figures->settlement;
        $credit = $figures->costOfCredit;
        $half = $figures->halfPaid;
        $mileage = $view->mileage;

        return [
            'id' => $agreement->id,
            'vehicle_id' => $agreement->vehicleId,
            'type' => $data->type->value,
            'lender' => $data->lender,
            'status' => $agreement->status->value,
            'started_on' => self::date($data->startedOn),
            'ends_on' => self::date($figures->endsOn),
            'ended_on' => self::date($agreement->endedOn),
            'currency' => $view->currency,
            'apr' => $data->type->isCredit() ? self::dec($data->apr, 3) : null,
            'payments_total' => $schedule->numberOfPayments,
            'payments_made' => $schedule->made(),
            'payments_remaining' => $schedule->remaining(),
            'next_payment' => ($next = $schedule->next()) === null ? null : [
                'due_on' => self::date($next->dueOn),
                'amount' => self::dec($next->amount, self::QUANTITY_SCALE),
            ],
            'remaining_to_pay' => $money($figures->remainingToPay),
            'optional_final_payment' => $figures->optionalFinal === null ? null : [
                'due_on' => self::date($figures->optionalFinal->dueOn),
                'amount' => self::dec($figures->optionalFinal->amount, self::QUANTITY_SCALE),
            ],
            'total_amount_payable' => $money($figures->totalAmountPayable),
            'total_amount_payable_worked_out' => $figures->totalDerived,
            'amount_of_credit' => $money($figures->amountOfCredit),
            'settlement' => $settlement === null ? null : [
                'amount' => $money($settlement->amount),
                'estimate' => $settlement->isEstimate(),
                'quoted_on' => self::date($settlement->quote?->quotedOn),
                'valid_until' => self::date($settlement->quote?->validUntil),
            ],
            'cost_of_credit' => $credit === null ? null : [
                'total' => $money($credit->total),
                'so_far' => $money($credit->soFar),
                'so_far_estimate' => $credit->soFar !== null && !$credit->exact,
            ],
            'half_paid' => $half === null ? null : [
                'target' => $money($half->target),
                'paid_so_far' => $money($half->paidSoFar),
                'still_needed' => $money($half->stillNeeded),
                'reached' => $half->reached,
                'on' => self::date($half->on),
            ],
            'equity' => $figures->equity === null ? null : [
                'amount' => $money($figures->equity->amount),
                'valuation' => $money($figures->equity->valuation),
                'valued_on' => self::date($figures->equity->valuedOn),
                'estimate' => $settlement?->isEstimate() ?? true,
            ],
            'mileage' => $mileage === null ? null : [
                'distance_unit' => self::DISTANCE_UNIT,
                'agreement_unit' => $mileage->unit->value,
                'allowance' => self::dec($mileage->allowanceKm, self::QUANTITY_SCALE),
                'start_odometer' => self::dec($mileage->startKm, self::QUANTITY_SCALE),
                'distance_so_far' => self::dec($mileage->distanceKm, self::QUANTITY_SCALE),
                'allowed_to_date' => self::dec($mileage->allowedToDateKm, self::QUANTITY_SCALE),
                'projected' => self::dec($mileage->projectedKm, self::QUANTITY_SCALE),
                'projected_excess' => self::dec($mileage->excessKm, self::QUANTITY_SCALE),
                'excess_charge_per_unit' => self::dec($mileage->chargePerUnit, 4),
                'projected_excess_charge' => $money($mileage->excessCharge),
                'projection_estimate' => $mileage->projectedKm !== null && $agreement->status->isActive(),
            ],
            'schedule' => array_map(static fn (ScheduledPayment $payment): array => [
                'number' => $payment->number,
                'kind' => $payment->kind->value,
                'due_on' => self::date($payment->dueOn),
                'amount' => self::dec($payment->amount, self::QUANTITY_SCALE),
                'status' => $payment->status->value,
                'paid_on' => self::date($payment->paidOn),
            ], $schedule->payments),
            'extra_payments' => array_map(static fn (PaymentEvent $event): array => [
                'paid_on' => self::date($event->paymentDate()),
                'amount' => self::dec($event->amount, self::QUANTITY_SCALE),
            ], $schedule->extras),
        ];
    }

    /**
     * A maintenance schedule with its due state (spec.md §7.4, §7.20 Phase
     * 39): the interval, the baseline, the stored last done and next due,
     * and the status the Maintenance tab shows, in the owner's lead times.
     * `due_on` is the date limit, or the projected day the distance limit
     * is reached (`due_on_projected`).
     *
     * @return array<string, mixed>
     */
    public static function schedule(ScheduleState $state): array
    {
        $schedule = $state->schedule;
        $data = $schedule->data;
        $due = $state->due;

        return [
            'id' => $schedule->id,
            'vehicle_id' => $schedule->vehicleId,
            'category' => $data->category->value,
            'title' => $data->title,
            'interval_km' => self::dec($data->intervalKm, self::QUANTITY_SCALE),
            'interval_months' => $data->intervalMonths,
            'baseline_done_on' => self::date($data->baselineDoneOn),
            'baseline_odometer' => self::dec($data->baselineDoneKm, self::QUANTITY_SCALE),
            'last_done_on' => self::date($schedule->lastDone->on),
            'last_done_odometer' => self::dec($schedule->lastDone->km, self::QUANTITY_SCALE),
            'next_due_on' => self::date($schedule->nextDue->on),
            'next_due_odometer' => self::dec($schedule->nextDue->km, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'status' => $due->status->value,
            'trigger' => $due->trigger?->value,
            'due_on' => self::date($due->dueOn),
            'due_on_projected' => $due->projected,
            'days_left' => $due->daysLeft,
            'distance_left' => self::dec($due->kmLeft, self::QUANTITY_SCALE),
            'created_at' => self::instant($schedule->createdAt),
            'updated_at' => self::instant($schedule->updatedAt),
        ];
    }

    /**
     * A valuation (spec.md §7.1, Phase 14.1): a figure someone quoted, in
     * the vehicle's currency.
     *
     * @return array<string, mixed>
     */
    public static function valuation(VehicleValuation $valuation, string $currency): array
    {
        $data = $valuation->data;

        return [
            'id' => $valuation->id,
            'vehicle_id' => $valuation->vehicleId,
            'valued_on' => self::date($data->valuedOn),
            'amount' => self::dec($data->amount, self::QUANTITY_SCALE),
            'currency' => $currency,
            'source' => $data->source,
            'notes' => $data->notes,
            'created_by' => $valuation->createdBy,
            'created_at' => self::instant($valuation->createdAt),
            'updated_at' => self::instant($valuation->updatedAt),
        ];
    }

    /**
     * A decimal at a fixed number of places ("0" → "0.000"), so every value
     * of a field has the same shape whatever produced it.
     */
    public static function dec(?string $value, int $scale): ?string
    {
        return $value === null ? null : Decimal::round($value, $scale);
    }

    /**
     * The canonical consumption unit of a kind (a bool means electricity or
     * liquid): litres, kWh or kg (CNG, Phase 31) per 100 km.
     */
    public static function consumptionUnit(EnergyKind|bool $kind): string
    {
        if (is_bool($kind)) {
            $kind = $kind ? EnergyKind::Electric : EnergyKind::Liquid;
        }

        return match ($kind) {
            EnergyKind::Liquid => 'l_per_100km',
            EnergyKind::Electric => 'kwh_per_100km',
            EnergyKind::Gas => 'kg_per_100km',
        };
    }

    /**
     * The canonical unit quantities of a kind are stored in.
     */
    public static function volumeUnit(EnergyKind $kind): string
    {
        return match ($kind) {
            EnergyKind::Liquid => 'l',
            EnergyKind::Electric => 'kwh',
            EnergyKind::Gas => 'kg',
        };
    }

    private static function per100Km(string $volume, string $distanceKm): ?string
    {
        return Decimal::compare($distanceKm, '0') > 0
            ? Decimal::divide(Decimal::multiply($volume, '100', 3), $distanceKm, self::CONSUMPTION_SCALE)
            : null;
    }
}
