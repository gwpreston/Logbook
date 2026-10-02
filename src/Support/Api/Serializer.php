<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use Logbook\Domain\Incident\DamageArea;
use Logbook\Service\Incident\ClaimsRow;
use Logbook\Service\Incident\IncidentView;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Compliance\DocumentState;
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
            'capacity_unit' => $data->fuelType === FuelType::Electric ? 'kwh' : 'l',
            'first_registered_on' => self::date($data->firstRegisteredOn),
            'first_inspection_due_on' => self::date($data->firstInspectionDueOn),
            'purchase_date' => self::date($data->purchaseDate),
            'sale_date' => self::date($data->saleDate),
            'currency' => $currency,
            'currency_override' => $data->currency,
            'status' => $vehicle->status->value,
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
        $electric = $data->fuel->isElectric();
        $out = [
            'id' => $entry->id,
            'vehicle_id' => $entry->vehicleId,
            'filled_at' => self::instant($data->filledAt),
            'odometer' => self::dec($data->odometerKm, self::QUANTITY_SCALE),
            'distance_unit' => self::DISTANCE_UNIT,
            'fuel' => $data->fuel->value,
            'grade' => $data->grade?->value,
            'energy' => $electric ? EnergyKind::Electric->value : EnergyKind::Liquid->value,
            'volume' => self::dec($data->volume, self::QUANTITY_SCALE),
            'volume_unit' => $electric ? 'kwh' : 'l',
            'is_partial' => $data->isPartial,
            'is_missed_previous' => $data->isMissedPrevious,
            'station' => $data->station,
            'notes' => $data->notes,
            'economy' => $fill === null ? null : self::economy($fill, $electric, $costs),
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
    private static function economy(FillEconomy $fill, bool $electric, bool $costs): array
    {
        return [
            'status' => $fill->status->value,
            'distance_since_previous' => self::dec($fill->distanceSincePreviousKm, self::QUANTITY_SCALE),
            'consumption_unit' => self::consumptionUnit($electric),
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
        $electric = $summary->kind === EnergyKind::Electric;
        $last = $summary->lastSegment;
        $out = [
            'fills' => $summary->fills,
            'segments' => $summary->segments,
            'consumption_unit' => self::consumptionUnit($electric),
            'volume_unit' => $electric ? 'kwh' : 'l',
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
                OdometerSource::Manual => null,
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
            'source_id' => $item->sourceId,
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
     * A decimal at a fixed number of places ("0" → "0.000"), so every value
     * of a field has the same shape whatever produced it.
     */
    public static function dec(?string $value, int $scale): ?string
    {
        return $value === null ? null : Decimal::round($value, $scale);
    }

    public static function consumptionUnit(bool $electric): string
    {
        return $electric ? 'kwh_per_100km' : 'l_per_100km';
    }

    private static function per100Km(string $volume, string $distanceKm): ?string
    {
        return Decimal::compare($distanceKm, '0') > 0
            ? Decimal::divide(Decimal::multiply($volume, '100', 3), $distanceKm, self::CONSUMPTION_SCALE)
            : null;
    }
}
