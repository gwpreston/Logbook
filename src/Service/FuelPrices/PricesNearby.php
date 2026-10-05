<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * *Prices nearby* on the Fuel stations page (spec.md §7.33 *Fuel stations
 * page*, Phase 33.4): *Cheapest near me*'s search with the same query, the
 * first ten by listed price or distance, each against the area average,
 * and the saving against what has been paid for the grade lately (with
 * the vehicle's costs only).
 * Null while no price provider is on or the user has no liquid-fuelled
 * vehicle, when the page is *Your stations* only.
 */
final readonly class PricesNearby
{
    public const int ROWS = 10;
    /** The smallest difference worth a banner: a tenth of a penny a litre, the price's last shown place. */
    private const string SMALLEST = '0.001';

    public function __construct(
        private FuelPriceConfig $config,
        private CheapestNear $near,
        private NearForm $form,
        private FuelService $fuel,
        private VehicleService $vehicles,
        private VehicleAccess $access,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed> $query the page's GET parameters
     */
    public function build(User $user, array $query): ?PricesNearbyView
    {
        $provider = $this->config->provider();
        if (!$provider instanceof BulkPriceProvider) {
            return null;
        }
        $vehicles = $this->form->vehicles($user);
        if ($vehicles === []) {
            return null;
        }
        $text = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
        $places = $this->form->places($user);
        $stations = $this->form->stations($user);
        $vehicle = NearForm::pick($vehicles, $text('vehicle')) ?? $vehicles[0];
        $from = $text('from');
        if ($from === '') {
            $from = $places === [] ? NearOrigin::HERE : 'place:' . $places[0]->id;
        }
        $origin = NearForm::origin($from, $text('lat'), $text('lng'), $places, $stations);
        $sort = $text('sort') === NearSort::Distance->value ? NearSort::Distance : NearSort::Price;
        $grades = $this->form->grades($provider, $vehicle, $this->config->settings());
        $grade = FuelGrade::tryFrom($text('grade'));
        if ($grade !== null && !in_array($grade, $grades, true)) {
            $grade = null;
        }
        $radius = CheapestNear::DEFAULT_RADIUS;
        $km = $user->preferences->distanceUnit->toKm((float) $radius);
        $result = $origin === null ? null : $this->near->search($origin, $vehicle, $grade, $km, false, $sort, self::ROWS);

        return new PricesNearbyView(
            provider: $provider,
            vehicles: $vehicles,
            vehicle: $vehicle,
            places: $places,
            from: $from,
            origin: $origin,
            position: $origin?->kind === NearOrigin::HERE ? ['lat' => $text('lat'), 'lng' => $text('lng')] : null,
            grades: $grades,
            grade: $result->grade ?? $grade,
            sort: $sort,
            radius: $radius,
            result: $result,
            saving: $result === null ? null : $this->saving($user, $vehicle, $result),
            needsPlace: $origin === null && $from === NearOrigin::HERE && $places === [],
        );
    }

    /**
     * The banner: the cheapest listed price against the user's own average
     * paid for the grade in the vehicle over the last 12 months, for a tank
     * (the usual fill); null when it is not cheaper, there are no fill-ups
     * of the grade, or the currencies differ.
     */
    private function saving(User $user, Vehicle $vehicle, NearResult $result): ?NearSaving
    {
        $currency = $result->provider->currency();
        $cheapest = null;
        foreach ($result->rows as $row) {
            if ($cheapest === null || Decimal::compare($row->listed->price, $cheapest->listed->price) < 0) {
                $cheapest = $row;
            }
        }
        // What was paid is spending: only with the vehicle's costs (spec.md §5 Costs).
        if (
            $cheapest === null
            || !$this->access->can($user, VehicleAbility::ViewCosts, $vehicle)
            || $this->vehicles->currencyFor($user, $vehicle) !== $currency
        ) {
            return null;
        }
        $paid = $this->averagePaid($vehicle, $result->grade);
        if ($paid === null) {
            return null;
        }
        $difference = Decimal::subtract($paid, $cheapest->listed->price);
        if (Decimal::compare($difference, self::SMALLEST) < 0) {
            return null;
        }

        return new NearSaving(
            $cheapest,
            $paid,
            Money::of(Decimal::multiply($difference, $result->profile->usualFill, 6), $currency),
            $result->profile->fillAssumed,
        );
    }

    /**
     * Cost ÷ volume of the vehicle's fill-ups of the grade in the last 12
     * months, per unit to 6 places; null without any.
     */
    private function averagePaid(Vehicle $vehicle, FuelGrade $grade): ?string
    {
        $since = $this->clock->now()->modify('-12 months');
        $cost = '0';
        $volume = '0';
        foreach ($this->fuel->entries($vehicle) as $entry) {
            $data = $entry->data;
            if ($data->grade !== $grade || $data->filledAt < $since || Decimal::compare($data->volume, '0') <= 0) {
                continue;
            }
            $cost = Decimal::add($cost, $data->totalCost);
            $volume = Decimal::add($volume, $data->volume);
        }

        return Decimal::compare($volume, '0') > 0 ? Decimal::divide($cost, $volume, 6) : null;
    }
}
