<?php

declare(strict_types=1);

namespace Logbook\Service\Insights;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Place;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\StationRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\FuelPrices\CheapestNear;
use Logbook\Service\FuelPrices\FillUpComparisons;
use Logbook\Service\FuelPrices\ListedPrices;
use Logbook\Service\FuelPrices\NearOrigin;
use Logbook\Service\FuelPrices\NearRow;
use Logbook\Service\Station\PlaceService;
use Logbook\Service\Station\StationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * *Fuel saving* (spec.md §7.8, Phase 42): what filling at the cheapest
 * station near the viewer's first place, instead of the usual one, would
 * save in a year, from figures *Cheapest near me* already has (§7.34).
 *
 * yearly saving = yearly volume × (usual price − the cheapest's effective
 * price per unit). The usual price is the usual station's fresh listed
 * price, else the 30-day average paid (#352); the yearly volume is the
 * grade's litres over 12 months, scaled to a year under 12 months of
 * history (#357). Shown from 20 a year in the currency's major unit (#353).
 * Exact decimals; rounded only when shown.
 */
final readonly class FuelSaving
{
    /** The smallest yearly saving shown, in the currency's major unit (#353). */
    public const string THRESHOLD = '20';
    public const int MIN_FILLS = 6;
    public const int MIN_DAYS = 90;
    public const int AVERAGE_DAYS = 30;
    private const int SCALE = 6;

    public function __construct(
        private FeatureToggles $features,
        private StationService $stations,
        private VehicleAccess $access,
        private FuelService $fuel,
        private StationRepository $stationRepository,
        private ListedPrices $listed,
        private CheapestNear $near,
        private PlaceService $places,
        private VehicleService $vehicles,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<FuelSavingFigure>
     */
    public function forVehicles(User $user, array $vehicles): array
    {
        $provider = $this->listed->provider();
        if (
            $provider === null || !$this->features->isEnabled(Feature::Fuel) || !$this->stations->enabled()
        ) {
            return [];
        }
        $place = $this->places->list($user)[0] ?? null;
        if ($place === null) {
            return [];
        }
        $figures = [];
        foreach ($vehicles as $vehicle) {
            if (!$this->access->can($user, VehicleAbility::ViewCosts, $vehicle)) {
                continue;
            }
            $currency = $this->vehicles->currencyFor($user, $vehicle);
            if ($currency !== $provider->currency()) {
                continue;
            }
            $figure = $this->figure($user, $vehicle, $place, $currency);
            if ($figure !== null) {
                $figures[] = $figure;
            }
        }

        return $figures;
    }

    private function figure(User $user, Vehicle $vehicle, Place $place, string $currency): ?FuelSavingFigure
    {
        $now = $this->clock->now();
        $origin = NearOrigin::place(
            $place->id,
            $place->data->name,
            (float) $place->data->latitude,
            (float) $place->data->longitude,
        );
        $result = $this->near->search(
            $origin,
            $vehicle,
            null,
            $user->preferences->distanceUnit->toKm((float) CheapestNear::DEFAULT_RADIUS),
        );
        $cheapest = $result?->rows[0] ?? null;
        // Only the vehicle's own grade (an EV has none): the search's fallback grade is not its fuel.
        if ($result === null || $cheapest === null || $result->profile->grade === null) {
            return null;
        }
        $grade = $result->grade;

        $entries = $this->fuel->entries($vehicle);
        $volume = self::yearlyVolume($entries, $grade, $now);
        if ($volume === null) {
            return null;
        }

        $usualId = FillUpComparisons::usualStationId($entries, $now);
        $usual = $usualId === null ? null : $this->stationRepository->resolve($usualId);
        if ($usual !== null && self::isUsual($cheapest, $usual->id, $usual->link?->ref)) {
            return null;
        }
        $listed = $usual === null ? null : $this->listed->forStation($usual)?->fresh($grade->value);
        $usualPrice = $listed->price ?? self::averagePaid($entries, $grade, $now);
        if ($usualPrice === null) {
            return null;
        }

        $cost = $cheapest->cost;
        $perUnit = Decimal::divide($cost->total, $cost->fill, self::SCALE);
        $saving = Decimal::multiply($volume['litres'], Decimal::subtract($usualPrice, $perUnit), self::SCALE);
        if (Decimal::compare($saving, self::THRESHOLD) < 0) {
            return null;
        }

        return new FuelSavingFigure(
            vehicle: $vehicle,
            grade: $grade,
            currency: $currency,
            yearlySaving: $saving,
            yearlyLitres: $volume['litres'],
            scaledFromMonths: $volume['months'],
            usualPrice: $usualPrice,
            usualFromAverage: $listed === null,
            usualName: $usual?->data->name,
            cheapestPerUnit: $perUnit,
            cheapestName: $cheapest->station->data->name ?? $cheapest->providerStation->data->name,
            fillAssumed: $result->profile->fillAssumed,
            detourCounted: $cost->detourLitres !== null,
            place: $place,
        );
    }

    /**
     * The litres of the grade over the last 12 months, from at least 6
     * fill-ups at least 90 days apart; scaled to a year when the vehicle's
     * liquid fill-ups start less than 12 months ago (#357): the litres
     * after the first ÷ the days from the first to the last × 365.
     *
     * @param list<FuelEntry> $entries in the order they happened
     * @return array{litres: string, months: int|null}|null months: the history scaled from, or null when not scaled
     */
    public static function yearlyVolume(array $entries, FuelGrade $grade, DateTimeImmutable $now): ?array
    {
        $since = $now->modify('-12 months');
        $liquid = array_values(array_filter(
            $entries,
            static fn (FuelEntry $e): bool => $e->data->fuel->kind() === EnergyKind::Liquid && $e->data->filledAt <= $now,
        ));
        $graded = array_values(array_filter(
            $liquid,
            static fn (FuelEntry $e): bool => $e->data->grade === $grade && $e->data->filledAt >= $since
                && Decimal::compare($e->data->volume, '0') > 0,
        ));
        if (count($graded) < self::MIN_FILLS) {
            return null;
        }
        $first = $graded[0]->data->filledAt;
        $last = $graded[count($graded) - 1]->data->filledAt;
        $seconds = $last->getTimestamp() - $first->getTimestamp();
        if ($seconds < self::MIN_DAYS * 86400) {
            return null;
        }

        $scaled = $liquid[0]->data->filledAt > $since;
        $litres = '0';
        foreach ($graded as $i => $entry) {
            if (!$scaled || $i > 0) {
                $litres = Decimal::add($litres, $entry->data->volume);
            }
        }
        if (!$scaled) {
            return ['litres' => $litres, 'months' => null];
        }
        $days = Decimal::divide((string) $seconds, '86400', self::SCALE);

        return [
            'litres' => Decimal::divide(Decimal::multiply($litres, '365', self::SCALE), $days, self::SCALE),
            'months' => max(1, (int) round($seconds / 86400 / 30.4375)),
        ];
    }

    /**
     * The average price paid for the grade over the last 30 days (total
     * cost ÷ volume), or none without a fill-up (#352).
     *
     * @param list<FuelEntry> $entries
     */
    public static function averagePaid(array $entries, FuelGrade $grade, DateTimeImmutable $now): ?string
    {
        $since = $now->modify('-' . self::AVERAGE_DAYS . ' days');
        $cost = '0';
        $volume = '0';
        foreach ($entries as $entry) {
            $data = $entry->data;
            if ($data->grade !== $grade || $data->filledAt < $since || $data->filledAt > $now) {
                continue;
            }
            $cost = Decimal::add($cost, $data->totalCost);
            $volume = Decimal::add($volume, $data->volume);
        }

        return Decimal::compare($volume, '0') > 0 ? Decimal::divide($cost, $volume, self::SCALE) : null;
    }

    /**
     * Whether the cheapest is the usual station: its Logbook station, or
     * the provider station the usual one is linked to.
     */
    private static function isUsual(NearRow $row, int $usualId, ?string $usualRef): bool
    {
        return $row->station?->id === $usualId || ($usualRef !== null && $row->providerStation->ref() === $usualRef);
    }
}
