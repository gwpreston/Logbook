<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Fuel\GradeComparison;
use Logbook\Support\Number\Decimal;

/**
 * Builds a vehicle's fuel profile from its fill-ups (spec.md §7.34): the
 * median volume of the last 10 full fills of a liquid fuel (else 40 L), the
 * consumption over the full-to-full segments that ended in the last 12
 * months (all time when none did; a plug-in hybrid's liquid series only),
 * and its reference grade (Phase 16).
 */
final readonly class VehicleFuelProfiles
{
    public const int FILLS = 10;
    public const string PERIOD = '-12 months';

    public function __construct(private FuelService $fuel)
    {
    }

    public function for(Vehicle $vehicle, DateTimeImmutable $now, ?DateTimeImmutable $before = null): VehicleFuelProfile
    {
        $history = $this->fuel->history($vehicle);
        $liquid = array_values(array_filter(
            $history->ofKind(EnergyKind::Liquid),
            static fn (FillEconomy $fill): bool => $before === null || $fill->entry->data->filledAt <= $before,
        ));

        $full = [];
        foreach (array_reverse($liquid) as $fill) {
            $data = $fill->entry->data;
            if (!$data->isPartial && Decimal::compare($data->volume, '0') > 0) {
                $full[] = $data->volume;
                if (count($full) === self::FILLS) {
                    break;
                }
            }
        }
        $usual = $full === [] ? VehicleFuelProfile::DEFAULT_FILL : Decimal::round(GradeComparison::median($full), 2);

        $measured = array_values(array_filter($liquid, static fn (FillEconomy $f): bool => $f->segment !== null));
        $since = ($before ?? $now)->modify(self::PERIOD);
        $recent = array_values(array_filter(
            $measured,
            static fn (FillEconomy $f): bool => $f->segment !== null && $f->segment->endedAt >= $since,
        ));
        $allTime = $recent === [];
        [$km, $litres] = self::totals($allTime ? $measured : $recent);

        $graded = array_values(array_filter(
            array_map(static fn (FillEconomy $f): FuelEntry => $f->entry, $liquid),
            static fn (FuelEntry $e): bool => $e->data->grade !== null,
        ));
        $grade = GradeComparison::reference($graded, $before ?? $now) ?? self::defaultGrade($vehicle);

        return new VehicleFuelProfile(
            $vehicle,
            $usual,
            $full === [],
            Decimal::compare($km, '0') > 0 ? $km : null,
            Decimal::compare($km, '0') > 0 ? $litres : null,
            $allTime,
            $grade,
        );
    }

    /**
     * The vehicle's own default grade, else E10 95 for petrol (and hybrids)
     * and B7 for diesel; none otherwise.
     */
    public static function defaultGrade(Vehicle $vehicle): ?FuelGrade
    {
        if ($vehicle->data->defaultGrade !== null) {
            return $vehicle->data->defaultGrade;
        }
        $family = FuelGrade::defaultFamilyFor($vehicle->data->fuelType);
        if ($family === null) {
            return null;
        }
        foreach (FuelGrade::forFamily($family) as $grade) {
            if ($grade === FuelGrade::E10_95 || $grade === FuelGrade::B7) {
                return $grade;
            }
        }

        return null;
    }

    /**
     * @param list<FillEconomy> $fills
     * @return array{0: string, 1: string} distance (km) and volume (litres)
     */
    private static function totals(array $fills): array
    {
        $km = '0';
        $litres = '0';
        foreach ($fills as $fill) {
            $km = Decimal::add($km, $fill->segment->distanceKm ?? '0');
            $litres = Decimal::add($litres, $fill->segment->volume ?? '0');
        }

        return [$km, $litres];
    }
}
